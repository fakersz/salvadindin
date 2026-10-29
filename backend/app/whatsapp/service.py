import base64
import hmac
import json
import re
import secrets
from datetime import UTC, datetime, timedelta

from sqlalchemy import select, text, update
from sqlalchemy.dialects.postgresql import insert

from app.auth.models import RateBucket, User
from app.auth.security import now
from app.core.errors import AppError
from app.finance.models import Category, FinancialAccount
from app.finance.schemas import TransactionCreate
from app.finance.service import add_transaction
from app.whatsapp.models import WhatsAppInbound, WhatsAppLink, WhatsAppLinkCode, WhatsAppPending
from app.whatsapp.parser import normalize, parse
from app.whatsapp.security import decrypt, digest, encrypt

TTL = timedelta(minutes=10)
ACTIVE = ("account", "confirmation")
HELP = 'Mensagem não compreendida. Use: "gastei 35 no mercado" ou "recebi 2100 de salário". Valores em reais, com vírgula para centavos. Não executamos pagamentos nem transferências.'


def limited(db, scope, identity, maximum):
    current = now()
    query = insert(RateBucket).values(
        key_hash=digest("rate:" + scope, identity),
        window=int(current.timestamp()) // 900,
        attempts=1,
        expires_at=current + timedelta(minutes=30),
    )
    count = db.scalar(
        query.on_conflict_do_update(
            index_elements=[RateBucket.key_hash, RateBucket.window],
            set_={"attempts": RateBucket.attempts + 1},
        ).returning(RateBucket.attempts)
    )
    return count > maximum


def revoke_codes(db, owner):
    db.execute(
        update(WhatsAppLinkCode)
        .where(
            WhatsAppLinkCode.user_id == owner,
            WhatsAppLinkCode.used_at.is_(None),
            WhatsAppLinkCode.revoked_at.is_(None),
        )
        .values(revoked_at=now())
    )


def retire(pending, state):
    pending.state = state
    pending.data_encrypted = None


def owner_lock(db, owner):
    user = db.scalar(select(User).where(User.id == owner).with_for_update())
    return user is not None and user.is_active


def consume_code(db, sender, body):
    if limited(db, "link", sender, 5):
        return "Aguarde 15 minutos antes de tentar vincular novamente.", "rate_limited"
    raw = re.sub(r"[\s-]", "", body.removeprefix("VINCULAR").strip().upper())
    if not re.fullmatch(r"[A-Z2-7]{16}", raw):
        return (
            "Código inválido ou expirado. Gere outro em Integrações no SalvaDinDin.",
            "invalid_code",
        )
    code_hash = digest("code", raw)
    code = db.scalar(select(WhatsAppLinkCode).where(WhatsAppLinkCode.code_hash == code_hash))
    if code is None or not owner_lock(db, code.user_id):
        return (
            "Código inválido ou expirado. Gere outro em Integrações no SalvaDinDin.",
            "invalid_code",
        )
    # Recheck after the owner lock: revocation, consumption and generation use this same lock.
    code = db.scalar(
        select(WhatsAppLinkCode)
        .where(
            WhatsAppLinkCode.code_hash == code_hash,
            WhatsAppLinkCode.used_at.is_(None),
            WhatsAppLinkCode.revoked_at.is_(None),
            WhatsAppLinkCode.expires_at > now(),
        )
        .execution_options(populate_existing=True)
    )
    existing = (
        db.scalar(select(WhatsAppLink).where(WhatsAppLink.user_id == code.user_id))
        if code
        else None
    )
    if code is None or existing:
        return (
            "Código inválido ou expirado. Gere outro em Integrações no SalvaDinDin.",
            "invalid_code",
        )
    db.add(
        WhatsAppLink(
            user_id=code.user_id,
            sender_hash=digest("sender", sender),
            identifier_encrypted=encrypt(sender),
            created_at=now(),
        )
    )
    code.used_at = now()
    revoke_codes(db, code.user_id)
    return (
        "Vinculação concluída. Envie uma receita ou despesa; pediremos confirmação antes de salvar.",
        "linked",
    )


def format_money(value):
    whole, cents = format(value, ".2f").split(".")
    return "R$ " + re.sub(r"(?<=\d)(?=(\d{3})+$)", ".", whole) + "," + cents


def confirmation(db, data, challenge, owner):
    account = db.scalar(
        select(FinancialAccount).where(
            FinancialAccount.id == data["account_id"],
            FinancialAccount.user_id == owner,
            FinancialAccount.is_active.is_(True),
        )
    )
    if account is None:
        return None
    category = (
        db.scalar(
            select(Category).where(
                Category.id == data["category_id"],
                Category.user_id == owner,
                Category.type == data["type"],
            )
        )
        if data.get("category_id")
        else None
    )
    # A category changed or was deleted between messages: ask again instead of guessing.
    if data.get("category_id") and category is None:
        return None
    from decimal import Decimal

    kind = "receita" if data["type"] == "income" else "despesa"
    label = category.name if category else "Sem categoria"
    return (
        f"Confirmar {kind} de {format_money(Decimal(data['amount']))}: {data['description']}, "
        f"na conta {account.name}? Categoria: {label}. "
        f"Responda SIM {challenge} ou NÃO {challenge}. Válido por 10 minutos."
    )


def pending_reply(db, link, pending, body):
    if pending.expires_at <= now():
        retire(pending, "expired")
        return "Confirmação expirada. Envie o lançamento novamente.", "expired"
    match = re.fullmatch(
        r"(SIM|NAO|CONTA\s+([1-9][0-9]?))\s+([A-Z2-7]{6})", normalize(body).upper()
    )
    if not match or not hmac.compare_digest(digest("challenge", match[3]), pending.challenge_hash):
        return (
            "Use o comando completo com o código da última confirmação, ou envie um novo lançamento.",
            "confirmation_required",
        )
    command, choice, challenge = match.groups()
    if command == "NAO":
        retire(pending, "cancelled")
        return "Lançamento cancelado.", "cancelled"
    data = json.loads(decrypt(pending.data_encrypted))
    if pending.state == "account":
        if choice is None or int(choice) > len(data["options"]):
            return (
                "Conta não identificada. Responda CONTA, o número da opção e o código informado.",
                "account_required",
            )
        data["account_id"] = data.pop("options")[int(choice) - 1]
        reply = confirmation(db, data, challenge, link.user_id)
        if not reply:
            retire(pending, "cancelled")
            return (
                "Conta ou categoria indisponível. Envie o lançamento novamente.",
                "unavailable_account",
            )
        pending.data_encrypted = encrypt(json.dumps(data))
        pending.state = "confirmation"
        return reply, "confirmation_required"
    if command != "SIM":
        return "Responda SIM ou NÃO seguido do código informado.", "confirmation_required"
    if not confirmation(db, data, challenge, link.user_id):
        retire(pending, "cancelled")
        return (
            "Conta ou categoria indisponível. Envie o lançamento novamente.",
            "unavailable_account",
        )
    # A single transaction owns inbound deduplication, pending consumption and financial insert.
    with db.begin_nested():
        try:
            row = add_transaction(db, link.user_id, TransactionCreate(**data))
        except AppError:
            retire(pending, "cancelled")
            return (
                "Não foi possível registrar. Confira a conta no SalvaDinDin e tente novamente.",
                "invalid_account",
            )
    pending.transaction_id = row.id
    retire(pending, "completed")
    return (
        "Receita registrada." if data["type"] == "income" else "Despesa registrada."
    ), "recorded"


def linked_message(db, link, message_hash, body, message_at):
    pending = db.scalar(
        select(WhatsAppPending).where(
            WhatsAppPending.link_id == link.id,
            WhatsAppPending.state.in_(ACTIVE),
        )
    )
    draft = parse(body)
    if draft is None:
        if pending and (re.match(r"^(sim|nao|não|conta)\b", body, re.IGNORECASE)):
            return pending_reply(db, link, pending, body)
        return HELP, "unrecognized"
    if pending:
        retire(pending, "cancelled")
        db.flush()
    accounts = list(
        db.scalars(
            select(FinancialAccount)
            .where(
                FinancialAccount.user_id == link.user_id,
                FinancialAccount.is_active.is_(True),
            )
            .order_by(FinancialAccount.name, FinancialAccount.id)
        )
    )
    if not accounts:
        return (
            "Conta não identificada. Crie ou reative uma conta no SalvaDinDin e envie novamente.",
            "account_required",
        )
    if len(accounts) > 20 and not draft.account_hint:
        return (
            "Informe a conta: acrescente 'na conta NOME EXATO' ao lançamento.",
            "account_required",
        )
    chosen = None
    if draft.account_hint:
        matches = [a for a in accounts if normalize(a.name) == normalize(draft.account_hint)]
        if len(matches) != 1:
            return (
                "Conta não identificada. Use o nome exato de uma conta ativa sua.",
                "account_required",
            )
        chosen = matches[0]
    elif len(accounts) == 1:
        chosen = accounts[0]
    candidates = list(
        db.scalars(
            select(Category).where(
                Category.user_id == link.user_id,
                Category.type == draft.kind,
            )
        )
    )
    cats = [c for c in candidates if normalize(c.name) == normalize(draft.category_name or "")]
    data = {
        "account_id": str(chosen.id) if chosen else None,
        "destination_account_id": None,
        "category_id": str(cats[0].id) if len(cats) == 1 else None,
        "type": draft.kind,
        "amount": format(draft.amount, ".2f"),
        "description": draft.description,
        "occurred_at": message_at.isoformat(),
    }
    challenge = base64.b32encode(secrets.token_bytes(4)).decode()[:6]
    if not chosen:
        data["options"] = [str(a.id) for a in accounts]
    db.add(
        WhatsAppPending(
            link_id=link.id,
            user_id=link.user_id,
            source_hash=message_hash,
            challenge_hash=digest("challenge", challenge),
            data_encrypted=encrypt(json.dumps(data)),
            state="confirmation" if chosen else "account",
            created_at=now(),
            expires_at=now() + TTL,
        )
    )
    if chosen:
        return confirmation(db, data, challenge, link.user_id), "confirmation_required"
    options = "\n".join(f"{i}. {a.name}" for i, a in enumerate(accounts, 1))
    return (
        f"Qual conta utilizar?\n{options}\nResponda CONTA 1 {challenge} (troque 1 pela opção). Válido por 10 minutos."
    ), "account_required"


def process_message(db, message):
    sender, identifier = message.get("from"), message.get("id")
    if not isinstance(sender, str) or not re.fullmatch(r"[0-9]{5,20}", sender):
        return
    if not isinstance(identifier, str) or not 1 <= len(identifier) <= 256:
        return
    try:
        stamp = message.get("timestamp")
        if not isinstance(stamp, str) or not re.fullmatch(r"[0-9]{1,12}", stamp):
            return
        message_at = datetime.fromtimestamp(int(stamp), UTC)
    except (ValueError, OverflowError, OSError):
        return
    # Reject stale/replayed and future messages before any business action or response.
    if not now() - timedelta(hours=24) < message_at <= now() + timedelta(minutes=2):
        return
    sender_hash, message_hash = digest("sender", sender), digest("message", identifier)
    # Cross-process serialization for a sender, then owner locks (same order everywhere).
    lock = int.from_bytes(bytes.fromhex(sender_hash)[:8], "big", signed=True)
    db.execute(text("SELECT pg_advisory_xact_lock(:key)"), {"key": lock})
    if db.get(WhatsAppInbound, message_hash):
        db.rollback()
        return
    inbound = WhatsAppInbound(
        message_hash=message_hash,
        sender_hash=sender_hash,
        message_at=message_at,
        received_at=now(),
        outcome="received",
        reply_state="none",
        attempts=0,
    )
    db.add(inbound)
    db.flush()
    if limited(db, "message", sender, 30):
        inbound.outcome = "rate_limited"
        db.commit()
        return
    body = (
        message.get("text", {}).get("body")
        if message.get("type") == "text" and isinstance(message.get("text"), dict)
        else None
    )
    link = db.scalar(select(WhatsAppLink).where(WhatsAppLink.sender_hash == sender_hash))
    had_link = link is not None
    if link:
        owner = link.user_id
        active = owner_lock(db, owner)
        link = (
            db.scalar(
                select(WhatsAppLink)
                .where(WhatsAppLink.sender_hash == sender_hash)
                .execution_options(populate_existing=True)
            )
            if active
            else None
        )
    if not isinstance(body, str) or not 1 <= len(body.strip()) <= 500:
        reply, outcome = HELP, "unrecognized"
    elif body.strip().upper().startswith("VINCULAR"):
        if had_link:
            reply, outcome = (
                "WhatsApp já vinculado. Para trocar de conta, desvincule pelo SalvaDinDin.",
                "already_linked",
            )
        else:
            reply, outcome = consume_code(db, sender, body.strip().upper())
    elif not link:
        reply, outcome = (
            "Conta não vinculada. Acesse salvadindin.online/app/settings/integrations para gerar seu código.",
            "unlinked",
        )
    else:
        reply, outcome = linked_message(db, link, message_hash, body.strip(), message_at)
    inbound.outcome = outcome
    inbound.reply_expires_at = message_at + timedelta(hours=24)
    if link and outcome in {"confirmation_required", "account_required"}:
        deadline = db.scalar(
            select(WhatsAppPending.expires_at).where(
                WhatsAppPending.link_id == link.id,
                WhatsAppPending.state.in_(ACTIVE),
            )
        )
        if deadline:
            inbound.reply_expires_at = min(inbound.reply_expires_at, deadline)
    inbound.recipient_encrypted = encrypt(sender)
    inbound.reply_encrypted = encrypt(reply)
    inbound.reply_state = "queued"
    inbound.retry_at = now()
    db.commit()


def process_payload(db, payload, phone_id):
    if not isinstance(payload, dict) or payload.get("object") != "whatsapp_business_account":
        return
    entries = payload.get("entry", [])
    if not isinstance(entries, list):
        return
    for entry in entries:
        changes = entry.get("changes", []) if isinstance(entry, dict) else []
        if not isinstance(changes, list):
            continue
        for change in changes:
            if not isinstance(change, dict) or change.get("field") != "messages":
                continue
            value = change.get("value", {})
            if not isinstance(value, dict) or value.get("messaging_product") != "whatsapp":
                continue
            metadata = value.get("metadata", {})
            if not isinstance(metadata, dict) or metadata.get("phone_number_id") != phone_id:
                continue
            messages = value.get("messages", [])
            if not isinstance(messages, list):
                continue
            for message in messages:
                if isinstance(message, dict):
                    process_message(db, message)
