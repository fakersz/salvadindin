from alembic import context

from app.auth import models  # noqa: F401
from app.core.config import get_settings
from app.db.base import Base
from app.db.session import get_engine
from app.finance import models as finance_models  # noqa: F401
from app.whatsapp import models as whatsapp_models  # noqa: F401

# Register auth models for migrations.
target_metadata = Base.metadata

if context.is_offline_mode():
    context.configure(
        url=get_settings().sqlalchemy_url.render_as_string(hide_password=False),
        target_metadata=target_metadata,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
    )
    with context.begin_transaction():
        context.run_migrations()
else:
    with get_engine().connect() as connection:
        context.configure(connection=connection, target_metadata=target_metadata)
        with context.begin_transaction():
            context.run_migrations()
