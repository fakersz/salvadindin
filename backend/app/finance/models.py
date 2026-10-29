import uuid
from datetime import datetime
from decimal import Decimal

from sqlalchemy import (
    Boolean,
    CheckConstraint,
    DateTime,
    ForeignKey,
    ForeignKeyConstraint,
    Index,
    Numeric,
    String,
    UniqueConstraint,
    Uuid,
    text,
)
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base


class FinancialAccount(Base):
    __tablename__ = "financial_accounts"
    __table_args__ = (
        UniqueConstraint("user_id", "id", name="uq_accounts_user_id"),
        CheckConstraint(
            "type IN ('checking','savings','cash','digital_wallet')", name="ck_account_type"
        ),
        CheckConstraint("currency = 'BRL'", name="ck_account_currency"),
        CheckConstraint("length(trim(name)) BETWEEN 1 AND 60", name="ck_account_name"),
        Index("ix_accounts_user_active", "user_id", "is_active"),
    )
    id: Mapped[uuid.UUID] = mapped_column(Uuid, primary_key=True, default=uuid.uuid4)
    user_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"))
    name: Mapped[str] = mapped_column(String(60))
    type: Mapped[str] = mapped_column(String(20))
    initial_balance: Mapped[Decimal] = mapped_column(Numeric(18, 2))
    currency: Mapped[str] = mapped_column(String(3), default="BRL")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))


class Category(Base):
    __tablename__ = "categories"
    __table_args__ = (
        UniqueConstraint("user_id", "id", "type", name="uq_categories_user_id_type"),
        CheckConstraint("type IN ('income','expense')", name="ck_category_type"),
        CheckConstraint("length(trim(name)) BETWEEN 1 AND 60", name="ck_category_name"),
        Index("uq_categories_name", "user_id", "type", text("lower(name)"), unique=True),
    )
    id: Mapped[uuid.UUID] = mapped_column(Uuid, primary_key=True, default=uuid.uuid4)
    user_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"))
    name: Mapped[str] = mapped_column(String(60))
    type: Mapped[str] = mapped_column(String(10))
    icon: Mapped[str | None] = mapped_column(String(16))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))


class Transaction(Base):
    __tablename__ = "transactions"
    __table_args__ = (
        ForeignKeyConstraint(
            ["user_id", "account_id"],
            ["financial_accounts.user_id", "financial_accounts.id"],
            name="fk_transaction_source",
        ),
        ForeignKeyConstraint(
            ["user_id", "destination_account_id"],
            ["financial_accounts.user_id", "financial_accounts.id"],
            name="fk_transaction_destination",
        ),
        ForeignKeyConstraint(
            ["user_id", "category_id", "type"],
            ["categories.user_id", "categories.id", "categories.type"],
            name="fk_transaction_category",
        ),
        CheckConstraint("type IN ('income','expense','transfer')", name="ck_transaction_type"),
        CheckConstraint("amount > 0", name="ck_transaction_amount"),
        CheckConstraint(
            "(type = 'transfer' AND destination_account_id IS NOT NULL AND destination_account_id <> account_id AND category_id IS NULL) OR (type <> 'transfer' AND destination_account_id IS NULL)",
            name="ck_transaction_transfer",
        ),
        Index("ix_transactions_user_date", "user_id", "occurred_at", "created_at", "id"),
        Index("ix_transactions_user_account", "user_id", "account_id", "occurred_at"),
        Index(
            "ix_transactions_user_destination", "user_id", "destination_account_id", "occurred_at"
        ),
        Index("ix_transactions_user_category", "user_id", "category_id", "occurred_at"),
    )
    id: Mapped[uuid.UUID] = mapped_column(Uuid, primary_key=True, default=uuid.uuid4)
    user_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"))
    account_id: Mapped[uuid.UUID] = mapped_column(Uuid)
    destination_account_id: Mapped[uuid.UUID | None] = mapped_column(Uuid)
    category_id: Mapped[uuid.UUID | None] = mapped_column(Uuid)
    type: Mapped[str] = mapped_column(String(10))
    amount: Mapped[Decimal] = mapped_column(Numeric(18, 2))
    description: Mapped[str] = mapped_column(String(240))
    occurred_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
