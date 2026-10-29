"""Shared financial mutations. Callers hold the owner row lock and commit atomically."""

from sqlalchemy import select

from app.auth.security import now
from app.core.errors import AppError
from app.finance.models import Category, FinancialAccount, Transaction
from app.finance.schemas import TransactionCreate


def find(db, model, identifier, owner):
    row = db.scalar(select(model).where(model.id == identifier, model.user_id == owner))
    if row is None:
        raise AppError(404, "not_found", "Registro não encontrado.")
    return row


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


def add_transaction(db, owner, data: TransactionCreate):
    values = data.model_dump()
    check_transaction(db, owner, values)
    row = Transaction(**values, user_id=owner, created_at=now(), updated_at=now())
    db.add(row)
    db.flush()
    return row
