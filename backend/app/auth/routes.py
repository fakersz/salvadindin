import hashlib
import re
import secrets
from datetime import timedelta
from typing import Annotated

from fastapi import APIRouter, Depends, Request, Response
from sqlalchemy import delete, select, update
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.auth.models import LoginSession, RecoveryCode, User
from app.auth.schemas import Credentials, Reauthenticate, Recovery, Registration
from app.auth.security import (
    CSRF_COOKIE,
    DUMMY_HASH,
    HASHER,
    SESSION_COOKIE,
    hash_password,
    issue_csrf,
    now,
    rate_limit,
    require_csrf,
    session_cookie,
    token_hash,
    verify_password,
)
from app.core.config import get_settings
from app.core.errors import AppError
from app.db.session import get_session

router = APIRouter(prefix="/api/auth", tags=["auth"])
Database = Annotated[Session, Depends(get_session)]


def public_user(user: User) -> dict:
    return {"id": str(user.id), "username": user.username, "created_at": user.created_at}


def require_auth(request: Request, db: Database) -> tuple[User, LoginSession]:
    raw = request.cookies.get(SESSION_COOKIE, "")
    if not raw or len(raw) > 128:
        raise AppError(401, "unauthenticated", "Entre para continuar.")
    row = db.execute(
        select(User, LoginSession)
        .join(LoginSession, User.id == LoginSession.user_id)
        .where(
            LoginSession.token_hash == token_hash(raw),
            LoginSession.revoked_at.is_(None),
            LoginSession.expires_at > now(),
            User.is_active.is_(True),
        )
    ).first()
    if row is None:
        raise AppError(401, "unauthenticated", "Entre para continuar.")
    return row[0], row[1]


Authenticated = Annotated[tuple[User, LoginSession], Depends(require_auth)]


def new_session(db: Session, user: User, response: Response, request: Request) -> None:
    # Replace the browser's previous session rather than preserving a fixed token.
    old = request.cookies.get(SESSION_COOKIE)
    if old:
        db.execute(
            update(LoginSession)
            .where(LoginSession.token_hash == token_hash(old), LoginSession.revoked_at.is_(None))
            .values(revoked_at=now())
        )
    raw = secrets.token_urlsafe(32)
    current = now()
    expires = current + timedelta(hours=get_settings().session_hours)
    db.add(
        LoginSession(
            user_id=user.id, token_hash=token_hash(raw), created_at=current, expires_at=expires
        )
    )
    user.last_login_at = current
    session_cookie(response, raw, expires)


def recovery_hash(user: User, code: str) -> str:
    canonical = code.replace("-", "").strip().upper()
    return hashlib.sha256(user.id.bytes + canonical.encode()).hexdigest()


def new_codes(db: Session, user: User) -> list[str]:
    db.execute(delete(RecoveryCode).where(RecoveryCode.user_id == user.id))
    codes = []
    for _ in range(10):
        raw = secrets.token_hex(16).upper()
        code = "-".join(raw[i : i + 8] for i in range(0, 32, 8))
        db.add(RecoveryCode(user_id=user.id, code_hash=recovery_hash(user, code), created_at=now()))
        codes.append(code)
    return codes


@router.get("/csrf")
def csrf(request: Request, response: Response):
    return {
        "csrf_token": issue_csrf(request, response),
        "registration_enabled": get_settings().public_registration_enabled,
    }


@router.post("/register", status_code=201, dependencies=[Depends(require_csrf)])
def register(data: Registration, request: Request, response: Response, db: Database):
    if not get_settings().public_registration_enabled:
        raise AppError(403, "registration_closed", "O cadastro está temporariamente fechado.")
    rate_limit(db, request, "register")
    current = now()
    user = User(
        username=data.username,
        password_hash=hash_password(data.password),
        created_at=current,
        updated_at=current,
        is_active=True,
    )
    db.add(user)
    try:
        db.flush()  # Database UNIQUE constraint handles concurrent registrations.
    except IntegrityError:
        db.rollback()
        raise AppError(
            409, "username_unavailable", "Este nome de usuário não está disponível."
        ) from None
    from app.finance.defaults import seed_categories

    seed_categories(db, user.id)
    codes = new_codes(db, user)
    new_session(db, user, response, request)
    result = {"user": public_user(user), "recovery_codes": codes}
    db.commit()
    return result


@router.post("/login", dependencies=[Depends(require_csrf)])
def login(data: Credentials, request: Request, response: Response, db: Database):
    rate_limit(db, request, "login", data.username)
    user = db.scalar(select(User).where(User.username == data.username).with_for_update())
    encoded = user.password_hash if user else DUMMY_HASH
    valid = verify_password(encoded, data.password)
    if user is None or not valid or not user.is_active:
        db.rollback()
        raise AppError(401, "invalid_credentials", "Usuário ou senha inválidos.")
    if HASHER.check_needs_rehash(user.password_hash):
        user.password_hash = hash_password(data.password)
        user.updated_at = now()
    new_session(db, user, response, request)
    result = {"user": public_user(user)}
    db.commit()
    return result


@router.get("/me")
def me(auth: Authenticated):
    return {"user": public_user(auth[0])}


@router.post("/logout", status_code=204, dependencies=[Depends(require_csrf)])
def logout(request: Request, response: Response, db: Database):
    db.execute(
        update(LoginSession)
        .where(
            LoginSession.token_hash == token_hash(request.cookies.get(SESSION_COOKIE, "")),
            LoginSession.revoked_at.is_(None),
        )
        .values(revoked_at=now())
    )
    db.commit()
    for name in (SESSION_COOKIE, CSRF_COOKIE):
        response.delete_cookie(name, path="/", secure=True, httponly=True, samesite="lax")


@router.post("/recover", dependencies=[Depends(require_csrf)])
def recover(data: Recovery, request: Request, db: Database):
    rate_limit(db, request, "recover", data.username)
    user = db.scalar(select(User).where(User.username == data.username).with_for_update())
    code = None
    if (
        user
        and user.is_active
        and re.fullmatch(r"[A-F0-9]{32}", data.recovery_code.replace("-", "").strip().upper())
    ):
        code = db.scalar(
            select(RecoveryCode)
            .where(
                RecoveryCode.user_id == user.id,
                RecoveryCode.code_hash == recovery_hash(user, data.recovery_code),
                RecoveryCode.used_at.is_(None),
            )
            .with_for_update()
        )
    # Equal expensive work for invalid and valid account/code combinations.
    encoded = hash_password(data.password)
    if code is None:
        db.rollback()
        raise AppError(400, "invalid_recovery", "Usuário ou código de recuperação inválido.")
    current = now()
    code.used_at = current
    user.password_hash = encoded
    user.updated_at = current
    db.execute(
        update(LoginSession)
        .where(LoginSession.user_id == user.id, LoginSession.revoked_at.is_(None))
        .values(revoked_at=current)
    )
    db.commit()
    return {"message": "Senha redefinida. Entre novamente com sua nova senha."}


@router.post("/recovery-codes/regenerate", dependencies=[Depends(require_csrf)])
def regenerate(data: Reauthenticate, request: Request, db: Database, auth: Authenticated):
    rate_limit(db, request, "regenerate", str(auth[0].id))
    user = db.scalar(
        select(User)
        .where(User.id == auth[0].id)
        .with_for_update()
        .execution_options(populate_existing=True)
    )
    require_auth(request, db)  # Recheck revocation after acquiring the user lock.
    if not verify_password(user.password_hash, data.password):
        db.rollback()
        raise AppError(401, "invalid_credentials", "Usuário ou senha inválidos.")
    codes = new_codes(db, user)
    db.commit()
    return {"recovery_codes": codes}
