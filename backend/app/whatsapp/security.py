import base64
import hashlib
import hmac
import re

from cryptography.fernet import Fernet

from app.auth.security import secret
from app.core.config import get_settings
from app.core.errors import AppError


def digest(scope: str, value: str) -> str:
    return hmac.new(secret(), f"whatsapp:{scope}:{value}".encode(), hashlib.sha256).hexdigest()


def cipher() -> Fernet:
    # A separate domain-separated 256-bit subkey; never use app/access tokens as data keys.
    key = hmac.new(secret(), b"salvadindin:whatsapp:encryption:v1", hashlib.sha256).digest()
    return Fernet(base64.urlsafe_b64encode(key))


def encrypt(value: str) -> str:
    return cipher().encrypt(value.encode()).decode()


def decrypt(value: str) -> str:
    return cipher().decrypt(value.encode()).decode()


def require_available():
    if not get_settings().whatsapp_ready:
        raise AppError(
            503,
            "whatsapp_unavailable",
            "Integração WhatsApp indisponível. Aguarde a configuração oficial.",
        )


def valid_signature(body: bytes, signature: str) -> bool:
    setting = get_settings().meta_app_secret
    if not setting or not re.fullmatch(r"sha256=[a-f0-9]{64}", signature):
        return False
    expected = (
        "sha256=" + hmac.new(setting.get_secret_value().encode(), body, hashlib.sha256).hexdigest()
    )
    return hmac.compare_digest(expected, signature)
