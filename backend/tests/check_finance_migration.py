"""Run only against the disposable migration database, never the host cluster."""

import secrets
import uuid
from datetime import UTC, datetime, timedelta

from alembic import command
from alembic.config import Config
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.auth.models import LoginSession, User
from app.auth.security import token_hash
from app.core.config import get_settings
from app.db.session import get_engine
from app.finance.models import Category, FinancialAccount, Transaction

settings = get_settings()
assert settings.app_env == "test"
assert settings.sqlalchemy_url.database == "salvadindin_migration_test"
engine = get_engine()
assert engine.dialect.name == "postgresql"
config = Config("alembic.ini")
command.upgrade(config, "0001_auth")
identifier = uuid.uuid4()
current = datetime.now(UTC)
with Session(engine) as db:
    db.add(
        User(
            id=identifier,
            username="isolated.migration",
            password_hash="disabled-test-only",
            is_active=False,
            created_at=current,
            updated_at=current,
        )
    )
    db.flush()
    db.add(
        LoginSession(
            user_id=identifier,
            token_hash=token_hash(secrets.token_urlsafe(32)),
            created_at=current,
            expires_at=current + timedelta(hours=1),
        )
    )
    db.commit()
command.upgrade(config, "head")
command.check(config)
with Session(engine) as db:
    assert db.scalar(select(func.count()).select_from(User)) == 1
    assert db.scalar(select(func.count()).select_from(LoginSession)) == 1
    assert (
        db.scalar(select(func.count()).select_from(Category).where(Category.user_id == identifier))
        == 7
    )
    assert db.scalar(select(func.count()).select_from(FinancialAccount)) == 0
    assert db.scalar(select(func.count()).select_from(Transaction)) == 0
print(
    "PASS: isolated upgrade preserves existing user/session and seeds categories without financial entries."
)
