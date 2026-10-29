import base64
import hmac
import json
import secrets

from fastapi import APIRouter, Request
from fastapi.responses import PlainTextResponse
from sqlalchemy import delete, select, update
from starlette.concurrency import run_in_threadpool

from app.auth.security import now
from app.core.config import get_settings
from app.core.errors import AppError
from app.finance.routes import Database, Owner, finish
from app.finance.schemas import Confirm
from app.whatsapp.models import WhatsAppInbound, WhatsAppLink, WhatsAppLinkCode, WhatsAppPending
from app.whatsapp.security import digest, require_available, valid_signature
from app.whatsapp.service import TTL, limited, process_payload, revoke_codes

router = APIRouter(prefix="/api/integrations/whatsapp", tags=["whatsapp"])
MAX_BODY = 256 * 1024


@router.get("/webhook")
def verify_webhook(request: Request):
    require_available()
    query = request.query_params
    token = query.get("hub.verify_token", "")
    if (
        query.get("hub.mode") != "subscribe"
        or not token.isascii()
        or not hmac.compare_digest(token, get_settings().whatsapp_verify_token.get_secret_value())
        or not query.get("hub.challenge", "").isdigit()
        or len(query["hub.challenge"]) > 128
    ):
        raise AppError(403, "invalid_verification", "Verificação inválida.")
    return PlainTextResponse(query["hub.challenge"])


@router.post("/webhook")
async def webhook(request: Request, db: Database):
    require_available()
    signature = request.headers.get("x-hub-signature-256", "")
    if not signature:
        raise AppError(403, "invalid_signature", "Assinatura inválida.")
    raw = bytearray()
    async for chunk in request.stream():
        raw.extend(chunk)
        if len(raw) > MAX_BODY:
            raise AppError(413, "payload_too_large", "Evento muito grande.")
    if not valid_signature(bytes(raw), signature):
        raise AppError(403, "invalid_signature", "Assinatura inválida.")
    try:
        payload = json.loads(raw)
    except (ValueError, UnicodeDecodeError, RecursionError):
        raise AppError(400, "invalid_event", "Evento inválido.") from None
    await run_in_threadpool(process_payload, db, payload, get_settings().whatsapp_phone_number_id)
    return {"status": "accepted"}


@router.get("/status")
def status(db: Database, owner: Owner):
    link = db.scalar(select(WhatsAppLink).where(WhatsAppLink.user_id == owner))
    pending = db.scalar(
        select(WhatsAppLinkCode)
        .where(
            WhatsAppLinkCode.user_id == owner,
            WhatsAppLinkCode.used_at.is_(None),
            WhatsAppLinkCode.revoked_at.is_(None),
            WhatsAppLinkCode.expires_at > now(),
        )
        .order_by(WhatsAppLinkCode.created_at.desc())
    )
    # Never expose phone identifiers, hashes, other users or tokens.
    return {
        "available": get_settings().whatsapp_ready,
        "connected": link is not None,
        "linked_at": link.created_at if link else None,
        "code_expires_at": pending.expires_at if pending else None,
    }


@router.post("/link-codes", status_code=201)
def generate_code(db: Database, owner: Owner):
    require_available()
    if limited(db, "generate", str(owner), 5):
        raise AppError(
            429,
            "rate_limited",
            "Aguarde 15 minutos para gerar outro código.",
            {"Retry-After": "900"},
        )
    if db.scalar(select(WhatsAppLink).where(WhatsAppLink.user_id == owner)):
        raise AppError(409, "already_linked", "Desvincule o WhatsApp antes de gerar outro código.")
    revoke_codes(db, owner)
    raw = base64.b32encode(secrets.token_bytes(10)).decode()
    expiry = now() + TTL
    db.add(
        WhatsAppLinkCode(
            user_id=owner, code_hash=digest("code", raw), created_at=now(), expires_at=expiry
        )
    )
    finish(db)
    return {"code": "-".join(raw[i : i + 4] for i in range(0, 16, 4)), "expires_at": expiry}


@router.delete("/link-codes", status_code=204)
def cancel_codes(data: Confirm, db: Database, owner: Owner):
    revoke_codes(db, owner)
    finish(db)


@router.delete("/link", status_code=204)
def unlink(data: Confirm, db: Database, owner: Owner):
    revoke_codes(db, owner)
    link = db.scalar(select(WhatsAppLink).where(WhatsAppLink.user_id == owner))
    if link:
        db.execute(delete(WhatsAppPending).where(WhatsAppPending.link_id == link.id))
        db.execute(
            update(WhatsAppInbound)
            .where(
                WhatsAppInbound.sender_hash == link.sender_hash,
                WhatsAppInbound.reply_state.in_(["queued", "retry"]),
            )
            .values(reply_state="cancelled", recipient_encrypted=None, reply_encrypted=None)
        )
        db.delete(link)
    finish(db)
