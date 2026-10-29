"""Signed synthetic events, disposable PostgreSQL only; no external API calls."""

import hashlib
import hmac
import json
import re
import uuid
from concurrent.futures import ThreadPoolExecutor
from datetime import timedelta
from decimal import Decimal
from unittest.mock import patch

import pytest
from fastapi.testclient import TestClient
from sqlalchemy import func, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session
from test_auth import register
from test_finance import account, api

from app.auth.models import User
from app.auth.security import now
from app.core.config import get_settings
from app.finance.models import Transaction
from app.whatsapp import delivery
from app.whatsapp.models import WhatsAppInbound, WhatsAppLink, WhatsAppLinkCode, WhatsAppPending
from app.whatsapp.parser import parse
from app.whatsapp.security import decrypt, encrypt
from app.whatsapp.service import process_message

ROOT = "/integrations/whatsapp"
WEBHOOK = "/api" + ROOT + "/webhook"
# Synthetic non-routable test identifiers: never sent to any real Meta endpoint.
SENDER = "00000000001"
OTHER = "00000000002"
APP_SECRET = "synthetic-meta-app-secret-only"


@pytest.fixture
def wa(auth, monkeypatch):
    for name, value in {
        "META_APP_SECRET": APP_SECRET,
        "WHATSAPP_VERIFY_TOKEN": "synthetic-verification",
        "WHATSAPP_ACCESS_TOKEN": "synthetic-access-token",
        "WHATSAPP_PHONE_NUMBER_ID": "00000000000",
        "WHATSAPP_API_VERSION": "v26.0",
    }.items():
        monkeypatch.setenv(name, value)
    get_settings.cache_clear()
    # Test-mode lifespan never starts the delivery worker.
    assert register(auth[0]).status_code == 201
    yield auth
    get_settings.cache_clear()


def payload(body, sender=SENDER, identifier=None, timestamp=None, phone_id="00000000000"):
    return {
        "object": "whatsapp_business_account",
        "entry": [
            {
                "changes": [
                    {
                        "field": "messages",
                        "value": {
                            "messaging_product": "whatsapp",
                            "metadata": {"phone_number_id": phone_id},
                            "messages": [
                                {
                                    "from": sender,
                                    "id": identifier or "synthetic." + uuid.uuid4().hex,
                                    "timestamp": timestamp or str(int(now().timestamp())),
                                    "type": "text",
                                    "text": {"body": body},
                                }
                            ],
                        },
                    }
                ]
            }
        ],
    }


def post(client, value, secret=APP_SECRET, raw=None):
    raw = raw if raw is not None else json.dumps(value).encode()
    signature = "sha256=" + hmac.new(secret.encode(), raw, hashlib.sha256).hexdigest()
    return client.post(
        WEBHOOK,
        content=raw,
        headers={"Content-Type": "application/json", "X-Hub-Signature-256": signature},
    )


def generate(client):
    response = api(client, "POST", ROOT + "/link-codes", {})
    assert response.status_code == 201
    return response.json()["code"]


def link(wa, sender=SENDER):
    code = generate(wa[0])
    assert post(wa[0], payload("VINCULAR " + code, sender)).status_code == 200
    assert api(wa[0], "GET", ROOT + "/status").json()["connected"]
    return code


def latest_reply(engine):
    with Session(engine) as db:
        row = db.scalar(select(WhatsAppInbound).order_by(WhatsAppInbound.received_at.desc()))
        return decrypt(row.reply_encrypted)


def challenge(engine):
    reply = latest_reply(engine)
    return re.search(r"(?:SIM|CONTA 1) ([A-Z2-7]{6})", reply)[1]


def count(engine, model):
    with Session(engine) as db:
        return db.scalar(select(func.count()).select_from(model))


def test_disabled_never_links(auth):
    client = auth[0]
    assert register(client).status_code == 201
    assert api(client, "GET", ROOT + "/status").json() == {
        "available": False,
        "connected": False,
        "linked_at": None,
        "code_expires_at": None,
    }
    assert api(client, "POST", ROOT + "/link-codes", {}).status_code == 503
    assert client.post(WEBHOOK, json={}).status_code == 503
    assert count(auth[1], WhatsAppLinkCode) == 0


def test_webhook_challenge_and_signature(wa):
    client = wa[0]
    params = {
        "hub.mode": "subscribe",
        "hub.verify_token": "synthetic-verification",
        "hub.challenge": "123456",
    }
    response = client.get(WEBHOOK, params=params)
    assert response.status_code == 200 and response.text == "123456"
    params["hub.verify_token"] = "wrong"
    assert client.get(WEBHOOK, params=params).status_code == 403
    value = payload("oi")
    assert post(client, value, secret="wrong").status_code == 403
    assert client.post(WEBHOOK, json=value).status_code == 403
    assert post(client, {}, raw=b"{ invalid-json").status_code == 400
    raw = json.dumps(value).encode()
    signature = "sha256=" + hmac.new(APP_SECRET.encode(), raw, hashlib.sha256).hexdigest()
    assert (
        client.post(
            WEBHOOK, content=raw + b" ", headers={"X-Hub-Signature-256": signature}
        ).status_code
        == 403
    )
    assert count(wa[1], WhatsAppInbound) == 0
    assert post(client, value).status_code == 200
    assert "não vinculada" in latest_reply(wa[1])


def test_webhook_wrong_recipient_status_stale_and_large(wa):
    assert post(wa[0], payload("oi", phone_id="00000000099")).status_code == 200
    assert (
        post(
            wa[0], payload("oi", timestamp=str(int((now() - timedelta(hours=25)).timestamp())))
        ).status_code
        == 200
    )
    assert (
        post(
            wa[0], payload("oi", timestamp=str(int((now() + timedelta(hours=1)).timestamp())))
        ).status_code
        == 200
    )
    assert post(wa[0], {"object": "whatsapp_business_account", "entry": []}).status_code == 200
    assert post(wa[0], {}, raw=b"x" * (256 * 1024 + 1)).status_code == 413
    assert count(wa[1], WhatsAppInbound) == 0


def test_link_hash_encryption_unique_and_reused(wa):
    code = link(wa)
    with Session(wa[1]) as db:
        row = db.scalar(select(WhatsAppLink))
        stored = db.scalar(select(WhatsAppLinkCode))
        assert row.identifier_encrypted != SENDER and decrypt(row.identifier_encrypted) == SENDER
        assert row.sender_hash != SENDER
        assert code.replace("-", "") not in stored.code_hash and stored.used_at
    assert post(wa[0], payload("VINCULAR " + code, OTHER)).status_code == 200
    assert count(wa[1], WhatsAppLink) == 1
    assert "inválido" in latest_reply(wa[1])
    result = api(wa[0], "GET", ROOT + "/status").text
    assert SENDER not in result and "encrypted" not in result and "hash" not in result
    assert api(wa[0], "POST", ROOT + "/link-codes", {}).status_code == 409


@pytest.mark.parametrize("mode", ["expired", "revoked", "superseded"])
def test_invalidated_codes(wa, mode):
    code = generate(wa[0])
    if mode == "expired":
        with Session(wa[1]) as db:
            db.scalar(select(WhatsAppLinkCode)).expires_at = now() - timedelta(seconds=1)
            db.commit()
    elif mode == "revoked":
        assert api(wa[0], "DELETE", ROOT + "/link-codes", {"confirm": True}).status_code == 204
    else:
        generate(wa[0])
    assert post(wa[0], payload("VINCULAR " + code)).status_code == 200
    assert count(wa[1], WhatsAppLink) == 0
    assert "inválido" in latest_reply(wa[1])


def test_link_attempt_and_generation_limits(wa):
    for _ in range(5):
        generate(wa[0])
    assert api(wa[0], "POST", ROOT + "/link-codes", {}).status_code == 429
    for _ in range(6):
        assert post(wa[0], payload("VINCULAR AAAA-AAAA-AAAA-AAAA")).status_code == 200
    assert "Aguarde" in latest_reply(wa[1])


@pytest.mark.parametrize(
    "body,kind,amount,description,category",
    [
        ("gastei 35 no mercado", "expense", "35.00", "mercado", "Alimentação"),
        ("paguei 80 de gasolina", "expense", "80.00", "gasolina", "Transporte"),
        ("recebi 2100 de salário", "income", "2100.00", "salário", "Salário"),
        ("entrada 150 pix", "income", "150.00", "pix", "Sem categoria"),
        ("despesa 45,90 almoço", "expense", "45.90", "almoço", "Alimentação"),
    ],
)
def test_supported_messages_need_confirmation(wa, body, kind, amount, description, category):
    account(wa[0])
    link(wa)
    assert post(wa[0], payload(body)).status_code == 200
    assert count(wa[1], Transaction) == 0
    assert category in latest_reply(wa[1])
    token = challenge(wa[1])
    assert post(wa[0], payload("SIM " + token)).status_code == 200
    with Session(wa[1]) as db:
        row = db.scalar(select(Transaction))
        assert row.type == kind and row.amount == Decimal(amount) and row.description == description
        assert db.scalar(select(WhatsAppPending)).data_encrypted is None
    assert "registrada" in latest_reply(wa[1])


@pytest.mark.parametrize(
    "text",
    [
        "gastei 35.90 no mercado",
        "gastei 35 e 20 no mercado",
        "transferir 20 pix",
        "gastei -35 no mercado",
        "gastei 0 no mercado",
        "gastei 1,234 no mercado",
        "gastei 20 amanhã",
        "gastei 99999999999999999 no mercado",
        "oi",
    ],
)
def test_ambiguous_parser(text):
    assert parse(text) is None


def test_decimal_thousands_and_no_floats():
    assert parse("despesa R$ 1.234,56 aluguel").amount == Decimal("1234.56")
    assert parse("entrada 0,01 pix").amount == Decimal("0.01")


def test_multiple_accounts_explicit_selection_and_stale_challenge(wa):
    a = account(wa[0], "Principal")
    account(wa[0], "Reserva")
    link(wa)
    assert post(wa[0], payload("gastei 10 no mercado")).status_code == 200
    token = challenge(wa[1])
    assert "Qual conta" in latest_reply(wa[1])
    assert post(wa[0], payload("SIM " + token)).status_code == 200
    assert count(wa[1], Transaction) == 0
    assert post(wa[0], payload("CONTA 1 " + token)).status_code == 200
    assert "Principal" in latest_reply(wa[1])
    assert post(wa[0], payload("SIM ERRADO")).status_code == 200
    assert count(wa[1], Transaction) == 0
    assert post(wa[0], payload("SIM " + token)).status_code == 200
    with Session(wa[1]) as db:
        assert str(db.scalar(select(Transaction)).account_id) == a["id"]
    assert post(wa[0], payload("despesa 20 almoço na conta Reserva")).status_code == 200
    assert "na conta Reserva" in latest_reply(wa[1])
    assert post(wa[0], payload("SIM " + token)).status_code == 200
    assert count(wa[1], Transaction) == 1


@pytest.mark.parametrize("mode", ["expired", "no", "archive", "unlink"])
def test_confirmation_cannot_write_after_invalidated(wa, mode):
    a = account(wa[0])
    link(wa)
    post(wa[0], payload("gastei 35 no mercado"))
    token = challenge(wa[1])
    if mode == "expired":
        with Session(wa[1]) as db:
            db.scalar(select(WhatsAppPending)).expires_at = now() - timedelta(seconds=1)
            db.commit()
    elif mode == "no":
        post(wa[0], payload("NÃO " + token))
    elif mode == "archive":
        api(wa[0], "DELETE", "/accounts/" + a["id"], {"confirm": True})
    else:
        assert api(wa[0], "DELETE", ROOT + "/link", {"confirm": True}).status_code == 204
    assert post(wa[0], payload("SIM " + token)).status_code == 200
    assert count(wa[1], Transaction) == 0


def test_dedup_concurrent_and_duplicate_confirmation(wa):
    account(wa[0])
    link(wa)
    data = payload("gastei 35 no mercado")
    message = data["entry"][0]["changes"][0]["value"]["messages"][0]

    def run():
        with Session(wa[1]) as db:
            process_message(db, message)

    with ThreadPoolExecutor(max_workers=2) as pool:
        list(pool.map(lambda _: run(), range(2)))
    assert count(wa[1], WhatsAppPending) == 1
    token = challenge(wa[1])
    confirmed = payload("SIM " + token)
    assert post(wa[0], confirmed).status_code == 200
    assert post(wa[0], confirmed).status_code == 200
    assert post(wa[0], payload("SIM " + token)).status_code == 200
    assert count(wa[1], Transaction) == 1


def test_isolation_and_csrf(wa):
    account(wa[0], "Conta A")
    link(wa)
    with TestClient(wa[2], base_url="https://testserver") as other:
        assert register(other, username="second.user").status_code == 201
        account(other, "Conta B")
        assert not api(other, "GET", ROOT + "/status").json()["connected"]
        code = generate(other)
        assert post(other, payload("VINCULAR " + code)).status_code == 200
        assert count(wa[1], WhatsAppLink) == 1
        assert post(other, payload("gastei 10 no mercado na conta Conta B")).status_code == 200
        assert "não identificada" in latest_reply(wa[1])
        assert count(wa[1], Transaction) == 0
        assert api(other, "DELETE", ROOT + "/link", {"confirm": True}).status_code == 204
        assert api(wa[0], "GET", ROOT + "/status").json()["connected"]
        assert other.post("/api" + ROOT + "/link-codes", json={}).status_code == 403
    with Session(wa[1]) as db:
        linkrow = db.scalar(select(WhatsAppLink))
        other_id = db.scalar(select(User.id).where(User.username == "second.user"))
        source = db.scalar(select(WhatsAppInbound))
        db.add(
            WhatsAppPending(
                link_id=linkrow.id,
                user_id=other_id,
                source_hash=source.message_hash,
                challenge_hash="0" * 64,
                data_encrypted=encrypt("{}"),
                state="confirmation",
                created_at=now(),
                expires_at=now() + timedelta(minutes=1),
            )
        )
        with pytest.raises(IntegrityError):
            db.flush()


def test_replies_retry_without_reposting_finance(wa, monkeypatch):
    account(wa[0])
    link(wa)
    post(wa[0], payload("despesa 45,90 almoço"))
    token = challenge(wa[1])
    confirmed = payload("SIM " + token)
    post(wa[0], confirmed)
    with Session(wa[1]) as db:
        for row in db.scalars(select(WhatsAppInbound)):
            if row.outcome != "recorded":
                delivery.clear_reply(row, "sent")
        db.commit()
    calls = []

    def send(recipient, body):
        calls.append((recipient, body))
        return (
            delivery.SendResult("retry", "http_429", 30)
            if len(calls) == 1
            else delivery.SendResult("sent")
        )

    monkeypatch.setattr(delivery, "send_text", send)
    assert delivery.dispatch_one(wa[1])
    assert count(wa[1], Transaction) == 1
    assert post(wa[0], confirmed).status_code == 200
    with Session(wa[1]) as db:
        row = db.scalar(select(WhatsAppInbound).where(WhatsAppInbound.outcome == "recorded"))
        row.retry_at = now() - timedelta(seconds=1)
        db.commit()
    assert delivery.dispatch_one(wa[1])
    assert len(calls) == 2 and calls[0] == calls[1]
    with Session(wa[1]) as db:
        row = db.scalar(select(WhatsAppInbound).where(WhatsAppInbound.outcome == "recorded"))
        assert (
            row.reply_state == "sent"
            and row.reply_encrypted is None
            and row.recipient_encrypted is None
        )
    assert count(wa[1], Transaction) == 1


def test_transport_failure_and_window_never_blindly_retry(wa, monkeypatch):
    link(wa)
    monkeypatch.setattr(
        delivery, "send_text", lambda *args: delivery.SendResult("uncertain", "transport")
    )
    assert delivery.dispatch_one(wa[1])
    assert not delivery.dispatch_one(wa[1])
    post(wa[0], payload("oi"))
    with Session(wa[1]) as db:
        row = db.scalar(select(WhatsAppInbound).where(WhatsAppInbound.reply_state == "queued"))
        row.message_at = now() - timedelta(hours=25)
        row.reply_expires_at = now() - timedelta(hours=1)
        db.commit()
    assert not delivery.dispatch_one(wa[1])
    with Session(wa[1]) as db:
        delivery.maintain(db)
        assert all(r.reply_encrypted is None for r in db.scalars(select(WhatsAppInbound)))


def test_official_api_payload_and_no_secret_in_errors(wa):
    class Response:
        status = 200

        def __enter__(self):
            return self

        def __exit__(self, *args):
            pass

        def read(self, *args):
            return b'{"messages":[{"id":"synthetic.accepted"}]}'

    with patch("urllib.request.OpenerDirector.open", return_value=Response()) as send:
        assert delivery.send_text(SENDER, "Teste isolado").state == "sent"
        request = send.call_args.args[0]
        assert request.full_url == "https://graph.facebook.com/v26.0/00000000000/messages"
        body = json.loads(request.data)
        assert body["type"] == "text" and not body["text"]["preview_url"]
        assert (
            body["to"] == SENDER
            and request.get_header("Authorization") == "Bearer synthetic-access-token"
        )
    import urllib.error

    for code, state in [(429, "retry"), (401, "failed"), (500, "uncertain")]:
        error = urllib.error.HTTPError("https://graph.facebook.com", code, "sensitive", {}, None)
        with patch("urllib.request.OpenerDirector.open", side_effect=error):
            result = delivery.send_text(SENDER, "Teste")
            assert result.state == state and "sensitive" not in str(result)


def test_confirmation_prompt_expires_before_service_window(wa, monkeypatch):
    account(wa[0])
    link(wa)
    post(wa[0], payload("gastei 35 no mercado"))
    with Session(wa[1]) as db:
        for row in db.scalars(select(WhatsAppInbound)):
            if row.outcome == "confirmation_required":
                row.reply_expires_at = now() - timedelta(seconds=1)
            else:
                delivery.clear_reply(row, "sent")
        db.commit()

    def forbidden(*args):
        pytest.fail("Expired confirmation cannot be sent")

    monkeypatch.setattr(delivery, "send_text", forbidden)
    assert not delivery.dispatch_one(wa[1])
    with Session(wa[1]) as db:
        delivery.maintain(db)
        assert all(row.reply_encrypted is None for row in db.scalars(select(WhatsAppInbound)))


def test_no_accounts_and_revoked_link_cannot_create_entries(wa):
    link(wa)
    post(wa[0], payload("gastei 35 no mercado"))
    assert "Conta não identificada" in latest_reply(wa[1])
    assert count(wa[1], WhatsAppPending) == 0
    assert api(wa[0], "DELETE", ROOT + "/link", {"confirm": True}).status_code == 204
    post(wa[0], payload("despesa 15 almoço"))
    assert "não vinculada" in latest_reply(wa[1])
    assert count(wa[1], Transaction) == 0
    assert not api(wa[0], "GET", ROOT + "/status").json()["connected"]


def test_explicit_account_hint_with_many_accounts(wa):
    # Large account lists require an explicit exact name instead of an oversized bot menu.
    from app.finance.models import FinancialAccount

    with Session(wa[1]) as db:
        owner = db.scalar(select(User.id))
        for i in range(21):
            db.add(
                FinancialAccount(
                    user_id=owner,
                    name=f"Conta {i}",
                    type="checking",
                    initial_balance=Decimal("0"),
                    currency="BRL",
                    is_active=True,
                    created_at=now(),
                    updated_at=now(),
                )
            )
        db.commit()
    link(wa)
    post(wa[0], payload("gastei 10 no mercado"))
    assert "nome exato" in latest_reply(wa[1]).lower()
    post(wa[0], payload("gastei 10 no mercado na conta Conta 20"))
    assert "na conta Conta 20" in latest_reply(wa[1])
    token = challenge(wa[1])
    post(wa[0], payload("SIM " + token))
    assert count(wa[1], Transaction) == 1
