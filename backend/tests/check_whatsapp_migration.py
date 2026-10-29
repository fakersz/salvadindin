"""Upgrade/downgrade compatibility on a named disposable database, never production."""

import uuid
from datetime import timedelta
from decimal import Decimal

from alembic import command
from alembic.config import Config
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.auth.models import LoginSession, User
from app.auth.security import now
from app.core.config import get_settings
from app.db.session import get_engine
from app.finance.models import FinancialAccount, Transaction
from app.whatsapp.models import WhatsAppInbound, WhatsAppLink, WhatsAppLinkCode, WhatsAppPending

settings = get_settings()
assert (
    settings.app_env == "test" and settings.sqlalchemy_url.database == "salvadindin_migration_test"
)
engine = get_engine()
config = Config("alembic.ini")
command.upgrade(config, "0002_finance")
with Session(engine) as db:
    user = User(
        id=uuid.uuid4(),
        username="migration.test",
        password_hash="disabled-synthetic",
        is_active=False,
        created_at=now(),
        updated_at=now(),
    )
    db.add(user)
    db.flush()
    user_id = user.id
    session = LoginSession(
        user_id=user_id,
        token_hash="1" * 64,
        created_at=now(),
        expires_at=now() + timedelta(hours=1),
    )
    account = FinancialAccount(
        id=uuid.uuid4(),
        user_id=user_id,
        name="Synthetic account",
        type="checking",
        initial_balance=Decimal("12.34"),
        currency="BRL",
        is_active=True,
        created_at=now(),
        updated_at=now(),
    )
    db.add_all([session, account])
    db.flush()
    entry = Transaction(
        id=uuid.uuid4(),
        user_id=user_id,
        account_id=account.id,
        type="income",
        amount=Decimal("0.01"),
        description="Synthetic migration check",
        occurred_at=now(),
        created_at=now(),
        updated_at=now(),
    )
    db.add(entry)
    db.commit()
    entry_id = entry.id
for revision in ["head", "0002_finance", "head"]:
    if revision == "0002_finance":
        command.downgrade(config, revision)
    else:
        command.upgrade(config, revision)
        command.check(config)
    with Session(engine) as db:
        assert db.get(User, user_id).password_hash == "disabled-synthetic"
        assert db.scalar(select(func.count()).select_from(LoginSession)) == 1
        assert db.get(Transaction, entry_id).amount == Decimal("0.01")
        assert db.scalar(select(FinancialAccount.initial_balance)) == Decimal("12.34")
with Session(engine) as db:
    for model in [WhatsAppLink, WhatsAppLinkCode, WhatsAppInbound, WhatsAppPending]:
        assert db.scalar(select(func.count()).select_from(model)) == 0
print(
    "PASS: additive WhatsApp migration, isolated reversal/re-upgrade, pre-existing user/session/account/transaction preserved; no seed links or financial records."
)
