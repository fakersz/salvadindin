"""Financial accounts, categories and single-row transfers with owner-safe references."""

import sqlalchemy as sa
from alembic import op

revision = "0002_finance"
down_revision = "0001_auth"
branch_labels = None
depends_on = None


def upgrade():
    op.create_table(
        "financial_accounts",
        sa.Column("id", sa.Uuid(), primary_key=True),
        sa.Column(
            "user_id", sa.Uuid(), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False
        ),
        sa.Column("name", sa.String(60), nullable=False),
        sa.Column("type", sa.String(20), nullable=False),
        sa.Column("initial_balance", sa.Numeric(18, 2), nullable=False),
        sa.Column("currency", sa.String(3), nullable=False),
        sa.Column("is_active", sa.Boolean(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.UniqueConstraint("user_id", "id", name="uq_accounts_user_id"),
        sa.CheckConstraint(
            "type IN ('checking','savings','cash','digital_wallet')", name="ck_account_type"
        ),
        sa.CheckConstraint("currency = 'BRL'", name="ck_account_currency"),
        sa.CheckConstraint("length(trim(name)) BETWEEN 1 AND 60", name="ck_account_name"),
    )
    op.create_index("ix_accounts_user_active", "financial_accounts", ["user_id", "is_active"])
    op.create_table(
        "categories",
        sa.Column("id", sa.Uuid(), primary_key=True),
        sa.Column(
            "user_id", sa.Uuid(), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False
        ),
        sa.Column("name", sa.String(60), nullable=False),
        sa.Column("type", sa.String(10), nullable=False),
        sa.Column("icon", sa.String(16)),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.UniqueConstraint("user_id", "id", "type", name="uq_categories_user_id_type"),
        sa.CheckConstraint("type IN ('income','expense')", name="ck_category_type"),
        sa.CheckConstraint("length(trim(name)) BETWEEN 1 AND 60", name="ck_category_name"),
    )
    op.create_index(
        "uq_categories_name", "categories", ["user_id", "type", sa.text("lower(name)")], unique=True
    )
    op.create_table(
        "transactions",
        sa.Column("id", sa.Uuid(), primary_key=True),
        sa.Column(
            "user_id", sa.Uuid(), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False
        ),
        sa.Column("account_id", sa.Uuid(), nullable=False),
        sa.Column("destination_account_id", sa.Uuid()),
        sa.Column("category_id", sa.Uuid()),
        sa.Column("type", sa.String(10), nullable=False),
        sa.Column("amount", sa.Numeric(18, 2), nullable=False),
        sa.Column("description", sa.String(240), nullable=False),
        sa.Column("occurred_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["user_id", "account_id"],
            ["financial_accounts.user_id", "financial_accounts.id"],
            name="fk_transaction_source",
        ),
        sa.ForeignKeyConstraint(
            ["user_id", "destination_account_id"],
            ["financial_accounts.user_id", "financial_accounts.id"],
            name="fk_transaction_destination",
        ),
        sa.ForeignKeyConstraint(
            ["user_id", "category_id", "type"],
            ["categories.user_id", "categories.id", "categories.type"],
            name="fk_transaction_category",
        ),
        sa.CheckConstraint("type IN ('income','expense','transfer')", name="ck_transaction_type"),
        sa.CheckConstraint("amount > 0", name="ck_transaction_amount"),
        sa.CheckConstraint(
            "(type = 'transfer' AND destination_account_id IS NOT NULL AND destination_account_id <> account_id AND category_id IS NULL) OR (type <> 'transfer' AND destination_account_id IS NULL)",
            name="ck_transaction_transfer",
        ),
    )
    op.create_index(
        "ix_transactions_user_date", "transactions", ["user_id", "occurred_at", "created_at", "id"]
    )
    op.create_index(
        "ix_transactions_user_account", "transactions", ["user_id", "account_id", "occurred_at"]
    )
    op.create_index(
        "ix_transactions_user_destination",
        "transactions",
        ["user_id", "destination_account_id", "occurred_at"],
    )
    op.create_index(
        "ix_transactions_user_category", "transactions", ["user_id", "category_id", "occurred_at"]
    )
    # Idempotent seed for existing users; no account, balance or transaction is inserted.
    op.execute("""INSERT INTO categories (id,user_id,name,type,created_at,updated_at)
        SELECT gen_random_uuid(), u.id, d.name, d.type, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        FROM users u CROSS JOIN (VALUES
          ('Salário','income'),('Outras receitas','income'),('Alimentação','expense'),
          ('Moradia','expense'),('Transporte','expense'),('Saúde','expense'),('Outras despesas','expense')
        ) AS d(name,type) ON CONFLICT DO NOTHING""")


def downgrade():
    op.drop_table("transactions")
    op.drop_table("categories")
    op.drop_table("financial_accounts")
