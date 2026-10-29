import uuid
from concurrent.futures import ThreadPoolExecutor
from datetime import UTC, datetime, timedelta
from decimal import Decimal

import pytest
from fastapi.testclient import TestClient
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session
from test_auth import ORIGIN, register

from app.auth.models import User
from app.finance.defaults import seed_categories
from app.finance.models import Transaction


def api(client, method, path, data=None):
    if method == "GET":
        return client.get("/api" + path)
    token = client.get("/api/auth/csrf").json()["csrf_token"]
    return client.request(
        method, "/api" + path, json=data, headers={"Origin": ORIGIN, "X-CSRF-Token": token}
    )


def account(client, name="Conta", initial="0.00"):
    response = api(
        client, "POST", "/accounts", {"name": name, "type": "checking", "initial_balance": initial}
    )
    assert response.status_code == 201
    return response.json()


def transaction(client, identifier, kind="income", amount="10.00", **extra):
    data = {
        "account_id": identifier,
        "type": kind,
        "amount": amount,
        "description": "Isolated test entry",
        "occurred_at": (datetime.now(UTC) - timedelta(minutes=1)).isoformat(),
        **extra,
    }
    return api(client, "POST", "/transactions", data)


def balance(client, identifier):
    return api(client, "GET", "/accounts/" + identifier).json()["balance"]


def start(auth):
    client = auth[0]
    assert register(client).status_code == 201
    return client


def test_empty_and_default_categories_idempotent(auth):
    client = start(auth)
    result = api(client, "GET", "/dashboard/summary").json()
    assert result["balance"] == result["income"] == result["expense"] == result["result"] == "0.00"
    assert result["accounts"] == result["recent_transactions"] == []
    categories = api(client, "GET", "/categories").json()["items"]
    assert len(categories) == 7
    with Session(auth[1]) as db:
        user = db.scalar(select(User))
        seed_categories(db, user.id)
        seed_categories(db, user.id)
        db.commit()
    assert len(api(client, "GET", "/categories").json()["items"]) == 7


def test_account_initial_and_edit(auth):
    client = start(auth)
    a = account(client, initial="1234.56")
    assert a["balance"] == "1234.56"
    result = api(
        client,
        "PATCH",
        "/accounts/" + a["id"],
        {"name": "Renamed", "type": "cash", "initial_balance": "-10.00"},
    )
    assert result.status_code == 200 and result.json()["balance"] == "-10.00"
    assert result.json()["name"] == "Renamed"


def test_income_expense_and_negative(auth):
    client = start(auth)
    a = account(client, initial="10.00")
    assert transaction(client, a["id"], "income", "20.10").status_code == 201
    assert transaction(client, a["id"], "expense", "40.20").status_code == 201
    assert balance(client, a["id"]) == "-10.10"
    summary = api(client, "GET", "/dashboard/summary").json()
    assert (
        summary["income"] == "20.10"
        and summary["expense"] == "40.20"
        and summary["result"] == "-20.10"
    )


def test_transfer_atomic_and_not_monthly_flow(auth):
    client = start(auth)
    a = account(client, "A", "100.00")
    b = account(client, "B", "20.00")
    response = transaction(client, a["id"], "transfer", "40.25", destination_account_id=b["id"])
    assert response.status_code == 201
    assert balance(client, a["id"]) == "59.75" and balance(client, b["id"]) == "60.25"
    result = api(client, "GET", "/dashboard/summary").json()
    assert result["balance"] == "120.00" and result["income"] == result["expense"] == "0.00"
    assert api(client, "GET", "/transactions").json()["total"] == 1
    edited = api(client, "PATCH", "/transactions/" + response.json()["id"], {"amount": "10.00"})
    assert edited.status_code == 200
    assert balance(client, a["id"]) == "90.00" and balance(client, b["id"]) == "30.00"
    assert (
        api(
            client, "DELETE", "/transactions/" + response.json()["id"], {"confirm": True}
        ).status_code
        == 204
    )
    assert balance(client, a["id"]) == "100.00" and balance(client, b["id"]) == "20.00"


def test_edit_and_delete_recalculate_and_confirm(auth):
    client = start(auth)
    a = account(client)
    t = transaction(client, a["id"], "income", "50.00").json()
    assert (
        api(
            client, "PATCH", "/transactions/" + t["id"], {"amount": "25.00", "type": "expense"}
        ).status_code
        == 200
    )
    assert balance(client, a["id"]) == "-25.00"
    assert api(client, "DELETE", "/transactions/" + t["id"], {"confirm": False}).status_code == 422
    assert api(client, "DELETE", "/transactions/" + t["id"], {"confirm": True}).status_code == 204
    assert balance(client, a["id"]) == "0.00"


def test_archive_preserves_history_balance_and_blocks_writes(auth):
    client = start(auth)
    a = account(client)
    b = account(client, "B")
    t = transaction(client, a["id"]).json()
    assert api(client, "DELETE", "/accounts/" + a["id"], {"confirm": True}).status_code == 204
    assert balance(client, a["id"]) == "10.00"
    assert transaction(client, a["id"]).status_code == 409
    assert (
        transaction(client, b["id"], "transfer", destination_account_id=a["id"]).status_code == 409
    )
    assert api(client, "PATCH", "/transactions/" + t["id"], {"amount": "20.00"}).status_code == 409
    assert api(client, "DELETE", "/transactions/" + t["id"], {"confirm": True}).status_code == 409
    assert api(client, "GET", "/transactions").json()["total"] == 1
    assert api(client, "GET", "/dashboard/summary").json()["balance"] == "10.00"
    assert api(client, "PATCH", "/accounts/" + a["id"], {"is_active": True}).status_code == 200
    assert transaction(client, a["id"]).status_code == 201


def test_categories_custom_edit_delete_and_type_integrity(auth):
    client = start(auth)
    a = account(client)
    c = api(
        client, "POST", "/categories", {"name": "Custom", "type": "expense", "icon": "★"}
    ).json()
    assert (
        api(client, "POST", "/categories", {"name": "custom", "type": "expense"}).status_code == 409
    )
    assert transaction(client, a["id"], "income", category_id=c["id"]).status_code == 422
    assert transaction(client, a["id"], "expense", category_id=c["id"]).status_code == 201
    assert api(client, "PATCH", "/categories/" + c["id"], {"name": "New label"}).status_code == 200
    assert api(client, "PATCH", "/categories/" + c["id"], {"type": "income"}).status_code == 409
    assert api(client, "DELETE", "/categories/" + c["id"], {"confirm": True}).status_code == 409
    result = api(client, "GET", "/dashboard/summary").json()
    assert result["expense_by_category"][0]["name"] == "New label"
    c2 = api(client, "POST", "/categories", {"name": "Unused", "type": "income"}).json()
    assert api(client, "DELETE", "/categories/" + c2["id"], {"confirm": True}).status_code == 204


@pytest.mark.parametrize(
    "amount",
    ["0.00", "-1.00", "0.001", "NaN", "Infinity", "1e2", "10000000000000000.00", 0.1, True],
)
def test_invalid_money(auth, amount):
    client = start(auth)
    a = account(client)
    assert transaction(client, a["id"], amount=amount).status_code == 422
    assert balance(client, a["id"]) == "0.00"


def test_decimal_precision_and_large_balance(auth):
    client = start(auth)
    a = account(client, initial="9999999999999999.99")
    for _ in range(3):
        assert transaction(client, a["id"], amount="0.10").status_code == 201
    assert balance(client, a["id"]) == "10000000000000000.29"
    assert api(client, "GET", "/dashboard/summary").json()["balance"] == "10000000000000000.29"


def test_invalid_relations_are_atomic(auth):
    client = start(auth)
    a = account(client, initial="100.00")
    assert (
        transaction(client, a["id"], "transfer", destination_account_id=a["id"]).status_code == 422
    )
    assert transaction(client, a["id"], "transfer").status_code == 422
    assert (
        transaction(
            client, a["id"], "transfer", destination_account_id=str(uuid.uuid4())
        ).status_code
        == 404
    )
    assert (
        transaction(client, a["id"], "expense", destination_account_id=a["id"]).status_code == 422
    )
    assert (
        balance(client, a["id"]) == "100.00"
        and api(client, "GET", "/transactions").json()["total"] == 0
    )


def test_filters_pagination_order_and_destination(auth):
    client = start(auth)
    a = account(client)
    b = account(client, "B")
    c = api(client, "POST", "/categories", {"name": "Filtered", "type": "expense"}).json()
    transaction(client, a["id"], "income", "20.00", occurred_at="2025-01-01T12:00:00-03:00")
    transaction(
        client,
        a["id"],
        "expense",
        "1.00",
        category_id=c["id"],
        occurred_at="2025-01-02T12:00:00-03:00",
    )
    transaction(
        client,
        a["id"],
        "transfer",
        "2.00",
        destination_account_id=b["id"],
        occurred_at="2025-01-03T12:00:00-03:00",
    )
    result = api(client, "GET", "/transactions?per_page=1&page=1").json()
    assert result["total"] == 3 and result["items"][0]["type"] == "transfer"
    assert (
        api(client, "GET", "/transactions?per_page=1&page=2").json()["items"][0]["type"]
        == "expense"
    )
    for query in [
        "type=expense",
        "category_id=" + c["id"],
        "account_id=" + b["id"],
        "start_date=2025-01-02&end_date=2025-01-02",
    ]:
        assert api(client, "GET", "/transactions?" + query).json()["total"] == 1
    assert (
        api(client, "GET", "/transactions?start_date=2025-02-01&end_date=2025-01-01").status_code
        == 422
    )
    assert api(client, "GET", "/transactions?per_page=101").status_code == 422
    assert api(client, "GET", "/transactions?end_date=9999-12-31").status_code == 422
    assert api(client, "GET", "/transactions?page=1000001").status_code == 422


def test_timezone_month_boundary_and_future(auth):
    client = start(auth)
    a = account(client)
    transaction(client, a["id"], "income", "10.00", occurred_at="2025-02-01T02:59:59Z")
    transaction(client, a["id"], "income", "20.00", occurred_at="2025-02-01T03:00:00Z")
    assert api(client, "GET", "/dashboard/summary?month=2025-01").json()["income"] == "10.00"
    assert api(client, "GET", "/dashboard/summary?month=2025-02").json()["income"] == "20.00"
    assert (
        api(client, "GET", "/transactions?start_date=2025-01-31&end_date=2025-01-31").json()[
            "total"
        ]
        == 1
    )
    assert transaction(client, a["id"], occurred_at="2025-02-01T03:00:00").status_code == 422
    future = (datetime.now(UTC) + timedelta(days=1)).isoformat()
    assert transaction(client, a["id"], amount="100.00", occurred_at=future).status_code == 201
    assert balance(client, a["id"]) == "30.00"
    assert api(client, "GET", "/dashboard/summary?month=2025-13").status_code == 422


def test_cross_user_access_and_database_constraints(auth):
    client = start(auth)
    a = account(client)
    t = transaction(client, a["id"]).json()
    c = api(client, "GET", "/categories").json()["items"][0]
    with TestClient(auth[2], base_url=ORIGIN) as other:
        register(other, "other.user")
        b = account(other)
        assert api(other, "GET", "/accounts/" + a["id"]).status_code == 404
        assert api(other, "PATCH", "/accounts/" + a["id"], {"name": "No"}).status_code == 404
        assert api(other, "DELETE", "/accounts/" + a["id"], {"confirm": True}).status_code == 404
        assert (
            api(other, "PATCH", "/transactions/" + t["id"], {"amount": "1.00"}).status_code == 404
        )
        assert (
            api(other, "DELETE", "/transactions/" + t["id"], {"confirm": True}).status_code == 404
        )
        assert transaction(other, a["id"]).status_code == 404
        assert (
            transaction(other, b["id"], "transfer", destination_account_id=a["id"]).status_code
            == 404
        )
        assert transaction(other, b["id"], c["type"], category_id=c["id"]).status_code == 404
        assert api(other, "GET", "/transactions?account_id=" + a["id"]).status_code == 404
        assert api(other, "GET", "/transactions").json()["total"] == 0
        assert api(other, "GET", "/dashboard/summary").json()["balance"] == "0.00"
        with Session(auth[1]) as db:
            other_id = db.scalar(select(User.id).where(User.username == "other.user"))
            db.add(
                Transaction(
                    user_id=other_id,
                    account_id=uuid.UUID(a["id"]),
                    type="income",
                    amount=Decimal("1.00"),
                    description="Constraint test",
                    occurred_at=datetime.now(UTC),
                    created_at=datetime.now(UTC),
                    updated_at=datetime.now(UTC),
                )
            )
            with pytest.raises(IntegrityError):
                db.commit()


def test_concurrent_incomes_and_transfers(auth):
    client = start(auth)
    a = account(client, initial="100.00")
    b = account(client, "B")
    cookie = client.cookies.get("__Host-salvadindin_session")

    def add(index):
        with TestClient(auth[2], base_url=ORIGIN) as own:
            own.cookies.set(
                "__Host-salvadindin_session", cookie, domain="testserver.local", path="/"
            )
            if index % 2:
                return transaction(
                    own, a["id"], "transfer", "0.10", destination_account_id=b["id"]
                ).status_code
            return transaction(own, a["id"], "income", "0.10").status_code

    with ThreadPoolExecutor(max_workers=4) as pool:
        assert list(pool.map(add, range(12))) == [201] * 12
    assert balance(client, a["id"]) == "100.00" and balance(client, b["id"]) == "0.60"
    assert api(client, "GET", "/dashboard/summary").json()["balance"] == "100.60"


def test_auth_csrf_and_empty_names(auth):
    client = auth[0]
    assert api(client, "GET", "/accounts").status_code == 401
    start(auth)
    assert client.post("/api/accounts", json={"name": "No", "type": "cash"}).status_code == 403
    assert api(client, "POST", "/accounts", {"name": "   ", "type": "cash"}).status_code == 422
    assert api(client, "POST", "/categories", {"name": "", "type": "income"}).status_code == 422


def test_concurrent_archive_and_entry_are_serialized(auth):
    client = start(auth)
    a = account(client)
    cookie = client.cookies.get("__Host-salvadindin_session")

    def act(operation):
        with TestClient(auth[2], base_url=ORIGIN) as own:
            own.cookies.set(
                "__Host-salvadindin_session", cookie, domain="testserver.local", path="/"
            )
            if operation == "archive":
                return api(own, "DELETE", "/accounts/" + a["id"], {"confirm": True}).status_code
            return transaction(own, a["id"]).status_code

    with ThreadPoolExecutor(max_workers=2) as pool:
        archived, written = list(pool.map(act, ["archive", "entry"]))
    assert archived == 204 and written in (201, 409)
    assert api(client, "GET", "/accounts/" + a["id"]).json()["is_active"] is False
    assert balance(client, a["id"]) == ("10.00" if written == 201 else "0.00")
    assert transaction(client, a["id"]).status_code == 409


def test_concurrent_category_deletion_and_entry_keep_integrity(auth):
    client = start(auth)
    a = account(client)
    c = api(client, "POST", "/categories", {"name": "Concurrent", "type": "expense"}).json()
    cookie = client.cookies.get("__Host-salvadindin_session")

    def act(operation):
        with TestClient(auth[2], base_url=ORIGIN) as own:
            own.cookies.set(
                "__Host-salvadindin_session", cookie, domain="testserver.local", path="/"
            )
            if operation == "delete":
                return api(own, "DELETE", "/categories/" + c["id"], {"confirm": True}).status_code
            return transaction(own, a["id"], "expense", category_id=c["id"]).status_code

    with ThreadPoolExecutor(max_workers=2) as pool:
        deleted, written = list(pool.map(act, ["delete", "entry"]))
    assert (deleted, written) in [(204, 404), (409, 201)]
    assert balance(client, a["id"]) == ("-10.00" if written == 201 else "0.00")
