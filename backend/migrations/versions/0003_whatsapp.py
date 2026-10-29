"""Official WhatsApp linking, inbound deduplication, confirmations and durable replies."""

import sqlalchemy as sa
from alembic import op

revision = "0003_whatsapp"
down_revision = "0002_finance"
branch_labels = None
depends_on = None


def upgrade():
    op.create_table(
        "whatsapp_links",
        sa.Column("id", sa.Uuid(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Uuid(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
            unique=True,
        ),
        sa.Column("sender_hash", sa.String(64), nullable=False, unique=True),
        sa.Column("identifier_encrypted", sa.Text(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.UniqueConstraint("user_id", "id", name="uq_whatsapp_links_user_id"),
    )
    op.create_table(
        "whatsapp_link_codes",
        sa.Column("id", sa.Uuid(), primary_key=True),
        sa.Column(
            "user_id", sa.Uuid(), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False
        ),
        sa.Column("code_hash", sa.String(64), nullable=False, unique=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("used_at", sa.DateTime(timezone=True)),
        sa.Column("revoked_at", sa.DateTime(timezone=True)),
    )
    op.create_index(
        "ix_whatsapp_codes_user_expiry", "whatsapp_link_codes", ["user_id", "expires_at"]
    )
    op.create_table(
        "whatsapp_inbound_messages",
        sa.Column("message_hash", sa.String(64), primary_key=True),
        sa.Column("sender_hash", sa.String(64), nullable=False),
        sa.Column("message_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("received_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("outcome", sa.String(32), nullable=False),
        sa.Column("recipient_encrypted", sa.Text()),
        sa.Column("reply_encrypted", sa.Text()),
        sa.Column("reply_state", sa.String(16), nullable=False),
        sa.Column("attempts", sa.Integer(), nullable=False),
        sa.Column("reply_expires_at", sa.DateTime(timezone=True)),
        sa.Column("retry_at", sa.DateTime(timezone=True)),
        sa.Column("lease_until", sa.DateTime(timezone=True)),
        sa.Column("error_code", sa.String(32)),
    )
    op.create_index(
        "ix_whatsapp_inbound_messages_sender_hash", "whatsapp_inbound_messages", ["sender_hash"]
    )
    op.create_index(
        "ix_whatsapp_reply_queue", "whatsapp_inbound_messages", ["reply_state", "retry_at"]
    )
    op.create_table(
        "whatsapp_pending_actions",
        sa.Column("id", sa.Uuid(), primary_key=True),
        sa.Column("link_id", sa.Uuid(), nullable=False),
        sa.Column(
            "user_id", sa.Uuid(), sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=False
        ),
        sa.Column(
            "source_hash",
            sa.String(64),
            sa.ForeignKey("whatsapp_inbound_messages.message_hash"),
            nullable=False,
            unique=True,
        ),
        sa.Column("challenge_hash", sa.String(64), nullable=False),
        sa.Column("data_encrypted", sa.Text()),
        sa.Column("state", sa.String(16), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column(
            "transaction_id", sa.Uuid(), sa.ForeignKey("transactions.id", ondelete="SET NULL")
        ),
        sa.ForeignKeyConstraint(
            ["user_id", "link_id"],
            ["whatsapp_links.user_id", "whatsapp_links.id"],
            name="fk_whatsapp_pending_owner",
            ondelete="CASCADE",
        ),
    )
    op.create_index(
        "uq_whatsapp_pending_link",
        "whatsapp_pending_actions",
        ["link_id"],
        unique=True,
        postgresql_where=sa.text("state IN ('account', 'confirmation')"),
    )
    op.create_index("ix_whatsapp_pending_expiry", "whatsapp_pending_actions", ["expires_at"])


def downgrade():
    op.drop_table("whatsapp_pending_actions")
    op.drop_table("whatsapp_inbound_messages")
    op.drop_table("whatsapp_link_codes")
    op.drop_table("whatsapp_links")
