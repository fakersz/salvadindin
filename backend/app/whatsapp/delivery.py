"""Durable reply queue. Financial processing never depends on a Meta send succeeding."""

import asyncio
import contextlib
import json
import logging
import urllib.error
import urllib.request
from contextlib import asynccontextmanager
from dataclasses import dataclass
from datetime import timedelta

from sqlalchemy import delete, or_, select, update
from sqlalchemy.orm import Session
from starlette.concurrency import run_in_threadpool

from app.auth.models import RateBucket
from app.auth.security import now
from app.core.config import get_settings
from app.db.session import get_engine
from app.whatsapp.models import WhatsAppInbound, WhatsAppLinkCode, WhatsAppPending
from app.whatsapp.security import decrypt

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class SendResult:
    state: str
    code: str | None = None
    retry_after: int = 60


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def send_text(recipient: str, body: str) -> SendResult:
    settings = get_settings()
    url = f"https://graph.facebook.com/{settings.whatsapp_api_version}/{settings.whatsapp_phone_number_id}/messages"
    request = urllib.request.Request(
        url,
        method="POST",
        data=json.dumps(
            {
                "messaging_product": "whatsapp",
                "recipient_type": "individual",
                "to": recipient,
                "type": "text",
                "text": {"body": body, "preview_url": False},
            }
        ).encode(),
        headers={
            "Authorization": "Bearer " + settings.whatsapp_access_token.get_secret_value(),
            "Content-Type": "application/json",
        },
    )
    try:
        # Fixed official host, TLS validation, no redirects or automatic POST retries.
        with urllib.request.build_opener(NoRedirect).open(request, timeout=5) as response:
            data = json.loads(response.read(16384))
            if response.status == 200 and data.get("messages", [{}])[0].get("id"):
                return SendResult("sent")  # Accepted by Meta, not proof of delivery/read.
            return SendResult("uncertain", "invalid_response")
    except urllib.error.HTTPError as error:
        if error.code == 429:
            retry = error.headers.get("Retry-After", "60")
            return SendResult(
                "retry", "http_429", max(30, min(900, int(retry))) if retry.isdigit() else 60
            )
        return SendResult("uncertain" if error.code >= 500 else "failed", f"http_{error.code}")
    except (OSError, ValueError, KeyError, IndexError, urllib.error.URLError):
        # Acceptance may be unknown after timeout/5xx. Never blindly resend.
        return SendResult("uncertain", "transport_or_response")


def clear_reply(row, state):
    row.reply_state = state
    row.reply_encrypted = None
    row.recipient_encrypted = None
    row.lease_until = None


def maintain(db):
    current = now()
    db.execute(delete(RateBucket).where(RateBucket.expires_at < current))
    db.execute(
        update(WhatsAppPending)
        .where(
            WhatsAppPending.state.in_(["account", "confirmation"]),
            WhatsAppPending.expires_at <= current,
        )
        .values(state="expired", data_encrypted=None)
    )
    db.execute(
        update(WhatsAppInbound)
        .where(
            or_(
                (WhatsAppInbound.reply_expires_at <= current)
                & WhatsAppInbound.reply_encrypted.is_not(None),
                (WhatsAppInbound.reply_state == "sending")
                & (WhatsAppInbound.lease_until < current),
            ),
        )
        .values(reply_encrypted=None, recipient_encrypted=None, reply_state="expired")
    )
    db.execute(
        delete(WhatsAppPending).where(WhatsAppPending.expires_at < current - timedelta(days=1))
    )
    db.execute(
        delete(WhatsAppLinkCode).where(WhatsAppLinkCode.expires_at < current - timedelta(days=1))
    )
    db.execute(
        delete(WhatsAppInbound).where(WhatsAppInbound.received_at < current - timedelta(days=30))
    )
    db.commit()


def dispatch_one(engine) -> bool:
    if not get_settings().whatsapp_ready:
        return False
    with Session(engine) as db:
        row = db.scalar(
            select(WhatsAppInbound)
            .where(
                WhatsAppInbound.reply_state.in_(["queued", "retry"]),
                WhatsAppInbound.retry_at <= now(),
                WhatsAppInbound.attempts < 3,
                WhatsAppInbound.reply_expires_at > now(),
                WhatsAppInbound.message_at > now() - timedelta(hours=24),
            )
            .order_by(WhatsAppInbound.received_at)
            .with_for_update(skip_locked=True)
            .limit(1)
        )
        if row is None:
            return False
        identifier = row.message_hash
        recipient, body = decrypt(row.recipient_encrypted), decrypt(row.reply_encrypted)
        row.reply_state = "sending"
        row.attempts += 1
        row.lease_until = now() + timedelta(seconds=30)
        db.commit()
    result = send_text(recipient, body)
    with Session(engine) as db:
        row = db.get(WhatsAppInbound, identifier)
        if row is None:
            return True
        row.error_code = result.code
        if (
            result.state == "retry"
            and row.attempts < 3
            and row.message_at > now() - timedelta(hours=24)
            and row.reply_expires_at > now()
        ):
            row.reply_state = "retry"
            row.retry_at = now() + timedelta(seconds=result.retry_after * row.attempts)
            row.lease_until = None
        else:
            clear_reply(row, "failed" if result.state == "retry" else result.state)
        db.commit()
    return True


def tick():
    engine = get_engine()
    with Session(engine) as db:
        maintain(db)
    for _ in range(5):
        if not dispatch_one(engine):
            break


async def worker():
    while True:
        try:
            await run_in_threadpool(tick)
        except Exception as error:
            # Do not include request/response bodies, phone IDs or exception text.
            logger.error("WhatsApp reply worker failed type=%s", type(error).__name__)
        await asyncio.sleep(5)


@asynccontextmanager
async def lifespan(app):
    task = (
        asyncio.create_task(worker())
        if get_settings().whatsapp_ready and get_settings().app_env != "test"
        else None
    )
    try:
        yield
    finally:
        if task:
            task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await task
