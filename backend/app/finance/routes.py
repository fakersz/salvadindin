import calendar
import uuid
from datetime import date, datetime, time, timedelta
from decimal import Decimal
from typing import Annotated
from zoneinfo import ZoneInfo

from fastapi import APIRouter, Depends, Query, Request
from sqlalchemy import case, func, or_, select, union_all
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.auth.models import User
from app.auth.routes import Authenticated, require_auth
from app.auth.security import now, require_csrf
from app.core.errors import AppError
from app.db.session import get_session
from app.finance.models import Category, FinancialAccount, Transaction
from app.finance.schemas import (
    AccountCreate,
    AccountPatch,
    CategoryCreate,
    CategoryPatch,
    Confirm,
    TransactionCreate,
    TransactionPatch,
)

router = APIRouter(prefix="/api", tags=["finance"])
Database = Annotated[Session, Depends(get_session)]
ZONE = ZoneInfo("America/Sao_Paulo")


def context(request: Request, db: Database, auth: Authenticated):
    writing = request.method != "GET"
    if writing:
        require_csrf(request)
    # All financial writers lock the owner; readers hold a shared lock so
    # multi-query balances and summaries cannot mix concurrent states.
    db.scalar(select(User).where(User.id == auth[0].id).with_for_update(read=not writing))
    require_auth(request, db)
    return auth[0].id


Owner = Annotated[uuid.UUID, Depends(context)]


def finish(db):
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise AppError(
            409, "financial_conflict", "Registro duplicado ou vinculado a movimentações."
        ) from None


def find(db, model, identifier, owner):
    row = db.scalar(select(model).where(model.id == identifier, model.user_id == owner))
    if row is None:
        raise AppError(404, "not_found", "Registro não encontrado.")
    return row


def money(value):
    return format(value or Decimal("0"), ".2f")


def accounts_data(db, owner, as_of):
    tx = Transaction
    outgoing = select(
        tx.account_id.label("account_id"),
        case((tx.type == "income", tx.amount), else_=-tx.amount).label("delta"),
    ).where(tx.user_id == owner, tx.occurred_at <= as_of)
    incoming = select(
        tx.destination_account_id.label("account_id"), tx.amount.label("delta")
    ).where(tx.user_id == owner, tx.type == "transfer", tx.occurred_at <= as_of)
    ledger = union_all(outgoing, incoming).subquery()
    totals = (
        select(ledger.c.account_id, func.sum(ledger.c.delta).label("delta"))
        .group_by(ledger.c.account_id)
        .subquery()
    )
    rows = db.execute(
        select(
            FinancialAccount,
            (FinancialAccount.initial_balance + func.coalesce(totals.c.delta, 0)).label("balance"),
        )
        .outerjoin(totals, totals.c.account_id == FinancialAccount.id)
        .where(FinancialAccount.user_id == owner)
        .order_by(
            FinancialAccount.is_active.desc(),
            func.lower(FinancialAccount.name),
            FinancialAccount.id,
        )
    ).all()
    return [
        {
            "id": str(a.id),
            "name": a.name,
            "type": a.type,
            "currency": a.currency,
            "initial_balance": money(a.initial_balance),
            "balance": money(balance),
            "is_active": a.is_active,
            "created_at": a.created_at,
            "updated_at": a.updated_at,
        }
        for a, balance in rows
    ]


def tx_data(row):
    return {
        key: (str(getattr(row, key)) if getattr(row, key) is not None else None)
        for key in ["id", "account_id", "destination_account_id", "category_id"]
    } | {
        "type": row.type,
        "amount": money(row.amount),
        "description": row.description,
        "occurred_at": row.occurred_at,
        "created_at": row.created_at,
        "updated_at": row.updated_at,
    }


def category_data(row):
    return {
        "id": str(row.id),
        "name": row.name,
        "type": row.type,
        "icon": row.icon,
        "created_at": row.created_at,
        "updated_at": row.updated_at,
    }


def active_accounts(db, owner, *identifiers):
    for identifier in set(i for i in identifiers if i):
        account = find(db, FinancialAccount, identifier, owner)
        if not account.is_active:
            raise AppError(
                409, "account_archived", "Reative a conta antes de alterar suas movimentações."
            )


def check_transaction(db, owner, values):
    active_accounts(db, owner, values["account_id"], values["destination_account_id"])
    if values["type"] == "transfer":
        if (
            not values["destination_account_id"]
            or values["destination_account_id"] == values["account_id"]
            or values["category_id"]
        ):
            raise AppError(
                422,
                "invalid_transfer",
                "Escolha duas contas diferentes e não use categoria na transferência.",
            )
    elif values["destination_account_id"]:
        raise AppError(
            422, "invalid_destination", "Destino é permitido apenas para transferências."
        )
    if values["category_id"]:
        category = find(db, Category, values["category_id"], owner)
        if category.type != values["type"]:
            raise AppError(
                422, "invalid_category", "A categoria precisa corresponder ao tipo da movimentação."
            )


@router.get("/accounts")
def accounts(db: Database, owner: Owner):
    return {"items": accounts_data(db, owner, now())}


@router.post("/accounts", status_code=201)
def create_account(data: AccountCreate, db: Database, owner: Owner):
    row = FinancialAccount(
        **data.model_dump(), user_id=owner, is_active=True, created_at=now(), updated_at=now()
    )
    db.add(row)
    finish(db)
    return next(a for a in accounts_data(db, owner, now()) if a["id"] == str(row.id))


@router.get("/accounts/{identifier}")
def get_account(identifier: uuid.UUID, db: Database, owner: Owner):
    find(db, FinancialAccount, identifier, owner)
    return next(a for a in accounts_data(db, owner, now()) if a["id"] == str(identifier))


@router.patch("/accounts/{identifier}")
def edit_account(identifier: uuid.UUID, data: AccountPatch, db: Database, owner: Owner):
    row = find(db, FinancialAccount, identifier, owner)
    values = data.model_dump(exclude_unset=True)
    if any(v is None for v in values.values()):
        raise AppError(422, "invalid_fields", "Preencha os campos da conta.")
    for key, value in values.items():
        setattr(row, key, value)
    row.updated_at = now()
    finish(db)
    return next(a for a in accounts_data(db, owner, now()) if a["id"] == str(identifier))


@router.delete("/accounts/{identifier}", status_code=204)
def archive_account(identifier: uuid.UUID, data: Confirm, db: Database, owner: Owner):
    row = find(db, FinancialAccount, identifier, owner)
    row.is_active = False
    row.updated_at = now()
    finish(db)


@router.get("/categories")
def categories(db: Database, owner: Owner):
    return {
        "items": [
            category_data(c)
            for c in db.scalars(
                select(Category)
                .where(Category.user_id == owner)
                .order_by(Category.type, func.lower(Category.name), Category.id)
            )
        ]
    }


@router.post("/categories", status_code=201)
def create_category(data: CategoryCreate, db: Database, owner: Owner):
    row = Category(**data.model_dump(), user_id=owner, created_at=now(), updated_at=now())
    db.add(row)
    finish(db)
    return category_data(row)


@router.patch("/categories/{identifier}")
def edit_category(identifier: uuid.UUID, data: CategoryPatch, db: Database, owner: Owner):
    row = find(db, Category, identifier, owner)
    values = data.model_dump(exclude_unset=True)
    if any(v is None for k, v in values.items() if k != "icon"):
        raise AppError(422, "invalid_fields", "Preencha os campos da categoria.")
    for key, value in values.items():
        setattr(row, key, value)
    row.updated_at = now()
    finish(db)
    return category_data(row)


@router.delete("/categories/{identifier}", status_code=204)
def delete_category(identifier: uuid.UUID, data: Confirm, db: Database, owner: Owner):
    row = find(db, Category, identifier, owner)
    if db.scalar(
        select(func.count())
        .select_from(Transaction)
        .where(Transaction.user_id == owner, Transaction.category_id == identifier)
    ):
        raise AppError(
            409,
            "category_in_use",
            "Esta categoria tem movimentações. Preserve o histórico ou altere as movimentações primeiro.",
        )
    db.delete(row)
    finish(db)


@router.get("/transactions/{identifier}")
def get_transaction(identifier: uuid.UUID, db: Database, owner: Owner):
    return tx_data(find(db, Transaction, identifier, owner))


@router.post("/transactions", status_code=201)
def create_transaction(data: TransactionCreate, db: Database, owner: Owner):
    values = data.model_dump()
    check_transaction(db, owner, values)
    row = Transaction(**values, user_id=owner, created_at=now(), updated_at=now())
    db.add(row)
    finish(db)
    return tx_data(row)


@router.patch("/transactions/{identifier}")
def edit_transaction(identifier: uuid.UUID, data: TransactionPatch, db: Database, owner: Owner):
    row = find(db, Transaction, identifier, owner)
    active_accounts(db, owner, row.account_id, row.destination_account_id)
    changes = data.model_dump(exclude_unset=True)
    if any(
        v is None for k, v in changes.items() if k not in {"category_id", "destination_account_id"}
    ):
        raise AppError(422, "invalid_fields", "Preencha os campos obrigatórios da movimentação.")
    values = {k: getattr(row, k) for k in TransactionCreate.model_fields} | changes
    check_transaction(db, owner, values)
    for key, value in changes.items():
        setattr(row, key, value)
    row.updated_at = now()
    finish(db)
    return tx_data(row)


@router.delete("/transactions/{identifier}", status_code=204)
def delete_transaction(identifier: uuid.UUID, data: Confirm, db: Database, owner: Owner):
    row = find(db, Transaction, identifier, owner)
    active_accounts(db, owner, row.account_id, row.destination_account_id)
    db.delete(row)
    finish(db)


def date_bounds(start, end):
    if start and end and start > end:
        raise AppError(422, "invalid_period", "A data inicial deve vir antes da data final.")
    try:
        return (
            datetime.combine(start, time.min, ZONE) if start else None,
            datetime.combine(end + timedelta(days=1), time.min, ZONE) if end else None,
        )
    except (ValueError, OverflowError):
        raise AppError(422, "invalid_period", "Escolha um intervalo de datas válido.") from None


@router.get("/transactions")
def transactions(
    db: Database,
    owner: Owner,
    start_date: date | None = None,
    end_date: date | None = None,
    type: str | None = Query(default=None, pattern="^(income|expense|transfer)$"),
    account_id: uuid.UUID | None = None,
    category_id: uuid.UUID | None = None,
    page: int = Query(1, ge=1, le=1000000),
    per_page: int = Query(20, ge=1, le=100),
):
    conditions = [Transaction.user_id == owner]
    start, end = date_bounds(start_date, end_date)
    if start:
        conditions.append(Transaction.occurred_at >= start)
    if end:
        conditions.append(Transaction.occurred_at < end)
    if type:
        conditions.append(Transaction.type == type)
    if account_id:
        find(db, FinancialAccount, account_id, owner)
        conditions.append(
            or_(
                Transaction.account_id == account_id,
                Transaction.destination_account_id == account_id,
            )
        )
    if category_id:
        find(db, Category, category_id, owner)
        conditions.append(Transaction.category_id == category_id)
    count = db.scalar(select(func.count()).select_from(Transaction).where(*conditions))
    rows = db.scalars(
        select(Transaction)
        .where(*conditions)
        .order_by(
            Transaction.occurred_at.desc(), Transaction.created_at.desc(), Transaction.id.desc()
        )
        .offset((page - 1) * per_page)
        .limit(per_page)
    )
    return {
        "items": [tx_data(row) for row in rows],
        "total": count,
        "page": page,
        "per_page": per_page,
    }


@router.get("/dashboard/summary")
def summary(
    db: Database, owner: Owner, month: str | None = Query(default=None, pattern=r"^\d{4}-\d{2}$")
):
    as_of = now()
    try:
        first = date.fromisoformat((month or as_of.astimezone(ZONE).strftime("%Y-%m")) + "-01")
        last = first.replace(day=calendar.monthrange(first.year, first.month)[1])
        start, end = date_bounds(first, last)
    except ValueError:
        raise AppError(422, "invalid_month", "Escolha um mês válido.") from None
    rows = accounts_data(db, owner, as_of)
    scope = [
        Transaction.user_id == owner,
        Transaction.occurred_at >= start,
        Transaction.occurred_at < end,
        Transaction.occurred_at <= as_of,
    ]
    totals = dict(
        db.execute(
            select(Transaction.type, func.sum(Transaction.amount))
            .where(*scope, Transaction.type.in_(["income", "expense"]))
            .group_by(Transaction.type)
        ).all()
    )
    income = totals.get("income", Decimal("0"))
    expense = totals.get("expense", Decimal("0"))
    distribution = db.execute(
        select(Category.id, Category.name, func.sum(Transaction.amount))
        .select_from(Transaction)
        .outerjoin(Category, (Category.id == Transaction.category_id) & (Category.user_id == owner))
        .where(*scope, Transaction.type == "expense")
        .group_by(Category.id, Category.name)
        .order_by(func.sum(Transaction.amount).desc(), Category.id)
    ).all()
    recent = db.scalars(
        select(Transaction)
        .where(Transaction.user_id == owner, Transaction.occurred_at <= as_of)
        .order_by(
            Transaction.occurred_at.desc(), Transaction.created_at.desc(), Transaction.id.desc()
        )
        .limit(5)
    )
    return {
        "as_of": as_of,
        "timezone": str(ZONE),
        "month": first.strftime("%Y-%m"),
        "balance": money(sum((Decimal(a["balance"]) for a in rows), Decimal("0"))),
        "income": money(income),
        "expense": money(expense),
        "result": money(income - expense),
        "accounts": rows,
        "recent_transactions": [tx_data(t) for t in recent],
        "expense_by_category": [
            {"category_id": str(i) if i else None, "name": n or "Sem categoria", "amount": money(a)}
            for i, n, a in distribution
        ],
    }
