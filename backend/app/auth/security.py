import hashlib
import hmac
import secrets
import threading
import time
from datetime import UTC, datetime, timedelta

from argon2 import PasswordHasher, Type
from argon2.exceptions import InvalidHashError, VerificationError
from fastapi import Request, Response
from sqlalchemy import delete
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.orm import Session

from app.auth.models import RateBucket
from app.core.config import get_settings
from app.core.errors import AppError

SESSION_COOKIE = "__Host-salvadindin_session"
CSRF_COOKIE = "__Host-salvadindin_csrf"
# Bounded concurrent Argon2 work keeps memory use predictable on this VPS.
HASHER = PasswordHasher(time_cost=3, memory_cost=32768, parallelism=1, type=Type.ID)
HASH_SLOTS = threading.BoundedSemaphore(2)
DUMMY_HASH = HASHER.hash(secrets.token_urlsafe(32))


def now() -> datetime:
    return datetime.now(UTC)


def token_hash(value: str) -> str:
    return hashlib.sha256(value.encode()).hexdigest()


def hash_password(value: str) -> str:
    with HASH_SLOTS:
        return HASHER.hash(value)


def verify_password(encoded: str, value: str) -> bool:
    with HASH_SLOTS:
        try:
            return HASHER.verify(encoded, value)
        except (VerificationError, InvalidHashError):
            return False


def secret() -> bytes:
    setting = get_settings().auth_secret
    if setting is None:
        raise AppError(503, "auth_unavailable", "Autenticação indisponível.")
    return setting.get_secret_value().encode()


def csrf_signature(payload: str, request: Request) -> str:
    binding = token_hash(request.cookies.get(SESSION_COOKIE, ""))
    return hmac.new(secret(), f"csrf:{payload}:{binding}".encode(), hashlib.sha256).hexdigest()


def valid_csrf(token: str, request: Request) -> bool:
    if len(token) > 256 or not token.isascii():
        return False
    parts = token.split(".")
    if len(parts) != 3:
        return False
    payload = ".".join(parts[:2])
    try:
        age = time.time() - int(parts[1])
    except ValueError:
        return False
    return 0 <= age < 7200 and hmac.compare_digest(parts[2], csrf_signature(payload, request))


def issue_csrf(request: Request, response: Response) -> str:
    token = request.cookies.get(CSRF_COOKIE, "")
    if not valid_csrf(token, request):
        payload = f"{secrets.token_urlsafe(24)}.{int(time.time())}"
        token = payload + "." + csrf_signature(payload, request)
    response.set_cookie(
        CSRF_COOKIE, token, secure=True, httponly=True, samesite="lax", path="/", max_age=7200
    )
    return token


def require_csrf(request: Request) -> None:
    origin = request.headers.get("origin", "")
    if (
        origin not in get_settings().auth_allowed_origins
        or request.headers.get("sec-fetch-site") == "cross-site"
        or request.headers.get("content-type", "").split(";")[0].strip() != "application/json"
    ):
        raise AppError(403, "csrf_failed", "Solicitação não autorizada. Atualize a página.")
    token = request.headers.get("x-csrf-token", "")
    cookie = request.cookies.get(CSRF_COOKIE, "")
    if (
        not token
        or not token.isascii()
        or not cookie.isascii()
        or not hmac.compare_digest(token, cookie)
        or not valid_csrf(token, request)
    ):
        raise AppError(403, "csrf_failed", "Solicitação não autorizada. Atualize a página.")


def session_cookie(response: Response, token: str, expires: datetime) -> None:
    response.set_cookie(
        SESSION_COOKIE,
        token,
        httponly=True,
        secure=True,
        samesite="lax",
        path="/",
        expires=expires,
        max_age=get_settings().session_hours * 3600,
    )


def rate_limit(db: Session, request: Request, action: str, account: str | None = None) -> None:
    ip = request.client.host if request.client else "unknown"
    limits = {"login": (30, 8), "register": (5, 5), "recover": (20, 5), "regenerate": (10, 5)}
    ip_limit, account_limit = limits[action]
    scopes = [(f"{action}:ip", ip, ip_limit)]
    if account is not None:
        scopes.append((f"{action}:account", account, account_limit))
    current = now()
    window = int(current.timestamp()) // 900
    for scope, identity, maximum in scopes:
        digest = hmac.new(secret(), f"rate:{scope}:{identity}".encode(), hashlib.sha256).hexdigest()
        statement = insert(RateBucket).values(
            key_hash=digest, window=window, attempts=1, expires_at=current + timedelta(minutes=30)
        )
        statement = statement.on_conflict_do_update(
            index_elements=[RateBucket.key_hash, RateBucket.window],
            set_={"attempts": RateBucket.attempts + 1},
        ).returning(RateBucket.attempts)
        attempts = db.scalar(statement)
        db.execute(delete(RateBucket).where(RateBucket.expires_at < current))
        db.commit()  # Persist attempts even when authentication fails later.
        if attempts > maximum * get_settings().auth_rate_limit_multiplier:
            retry = (window + 1) * 900 - int(current.timestamp())
            raise AppError(
                429,
                "rate_limited",
                "Muitas tentativas. Aguarde antes de tentar novamente.",
                {"Retry-After": str(retry)},
            )
