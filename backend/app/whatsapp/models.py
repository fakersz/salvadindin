"""Minimal retained metadata; phone IDs, drafts and outbound text are encrypted."""

import uuid
from datetime import datetime

from sqlalchemy import (
    DateTime,
    ForeignKey,
    ForeignKeyConstraint,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
    Uuid,
    text,
)
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base


class WhatsAppLink(Base):
    __tablename__ = "whatsapp_links"
    __table_args__ = (UniqueConstraint("user_id", "id", name="uq_whatsapp_links_user_id"),)
    id: Mapped[uuid.UUID] = mapped_column(Uuid, primary_key=True, default=uuid.uuid4)
    user_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), unique=True
    )
    sender_hash: Mapped[str] = mapped_column(String(64), unique=True)
    identifier_encrypted: Mapped[str] = mapped_column(Text)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))


class WhatsAppLinkCode(Base):
    __tablename__ = "whatsapp_link_codes"
    __table_args__ = (Index("ix_whatsapp_codes_user_expiry", "user_id", "expires_at"),)
    id: Mapped[uuid.UUID] = mapped_column(Uuid, primary_key=True, default=uuid.uuid4)
    user_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"))
    code_hash: Mapped[str] = mapped_column(String(64), unique=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    revoked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))


class WhatsAppInbound(Base):
    __tablename__ = "whatsapp_inbound_messages"
    __table_args__ = (Index("ix_whatsapp_reply_queue", "reply_state", "retry_at"),)
    message_hash: Mapped[str] = mapped_column(String(64), primary_key=True)
    sender_hash: Mapped[str] = mapped_column(String(64), index=True)
    message_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    received_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    outcome: Mapped[str] = mapped_column(String(32))
    recipient_encrypted: Mapped[str | None] = mapped_column(Text)
    reply_encrypted: Mapped[str | None] = mapped_column(Text)
    reply_state: Mapped[str] = mapped_column(String(16), default="none")
    attempts: Mapped[int] = mapped_column(Integer, default=0)
    reply_expires_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    retry_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    lease_until: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    error_code: Mapped[str | None] = mapped_column(String(32))


class WhatsAppPending(Base):
    __tablename__ = "whatsapp_pending_actions"
    __table_args__ = (
        ForeignKeyConstraint(
            ["user_id", "link_id"],
            ["whatsapp_links.user_id", "whatsapp_links.id"],
            name="fk_whatsapp_pending_owner",
            ondelete="CASCADE",
        ),
        Index(
            "uq_whatsapp_pending_link",
            "link_id",
            unique=True,
            postgresql_where=text("state IN ('account', 'confirmation')"),
        ),
        Index("ix_whatsapp_pending_expiry", "expires_at"),
    )
    id: Mapped[uuid.UUID] = mapped_column(Uuid, primary_key=True, default=uuid.uuid4)
    link_id: Mapped[uuid.UUID] = mapped_column(Uuid)
    user_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"))
    source_hash: Mapped[str] = mapped_column(
        ForeignKey("whatsapp_inbound_messages.message_hash"), unique=True
    )
    challenge_hash: Mapped[str] = mapped_column(String(64))
    data_encrypted: Mapped[str | None] = mapped_column(Text)
    state: Mapped[str] = mapped_column(String(16))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    transaction_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("transactions.id", ondelete="SET NULL")
    )
