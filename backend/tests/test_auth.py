import os
from concurrent.futures import ThreadPoolExecutor
from datetime import timedelta

import pytest
from fastapi.testclient import TestClient
from sqlalchemy import create_engine, delete, func, select
from sqlalchemy.engine import make_url
from sqlalchemy.orm import Session

from app.auth.models import LoginSession, RecoveryCode, User
from app.auth.security import SESSION_COOKIE, now, token_hash
from app.core.config import get_settings
from app.db.base import Base
from app.db.session import get_session
from app.main import create_app

PASSWORD = "Test-only long password! ç🔒"
ORIGIN = "https://testserver"


@pytest.fixture(scope="module")
def engine():
    url = os.environ.get("TEST_DATABASE_URL")
    if not url:
        pytest.skip("Requires disposable PostgreSQL via Dockerfile.test")
    assert make_url(url).database == "salvadindin_auth_test"
    engine = create_engine(url, pool_size=5)
    with engine.connect() as connection:
        assert connection.scalar(select(func.current_database())) == "salvadindin_auth_test"
    yield engine
    engine.dispose()


@pytest.fixture
def auth(engine, monkeypatch):
    monkeypatch.setenv("PUBLIC_REGISTRATION_ENABLED", "true")
    monkeypatch.setenv("AUTH_ALLOWED_ORIGINS", '["https://testserver"]')
    with Session(engine) as session:
        for table in reversed(Base.metadata.sorted_tables):
            session.execute(delete(table))
        session.commit()
    get_settings.cache_clear()
    app = create_app()

    def database():
        with Session(engine) as session:
            yield session

    app.dependency_overrides[get_session] = database
    with TestClient(app, base_url=ORIGIN) as client:
        yield client, engine, app
    get_settings.cache_clear()


def post(client, path, data, **kwargs):
    token = client.get("/api/auth/csrf").json()["csrf_token"]
    return client.post(
        "/api/auth/" + path, json=data, headers={"Origin": ORIGIN, "X-CSRF-Token": token, **kwargs}
    )


def register(client, username="test.user", password=PASSWORD):
    return post(
        client,
        "register",
        {"username": username, "password": password, "password_confirmation": password},
    )


def test_registration_hashes_and_public_fields(auth):
    client, engine, _ = auth
    response = register(client, "Test.User")
    assert response.status_code == 201
    data = response.json()
    assert data["user"]["username"] == "test.user"
    assert len(data["recovery_codes"]) == 10
    assert len(set(data["recovery_codes"])) == 10
    assert set(data["user"]) == {"id", "username", "created_at"}
    cookie = response.headers["set-cookie"]
    assert "HttpOnly" in cookie and "Secure" in cookie and "SameSite=lax" in cookie
    assert "Domain=" not in cookie
    assert response.headers["cache-control"] == "no-store"
    with Session(engine) as db:
        user = db.scalar(select(User))
        assert user.password_hash.startswith("$argon2id$")
        assert PASSWORD not in user.password_hash
        raw = client.cookies.get(SESSION_COOKIE)
        session = db.scalar(select(LoginSession))
        assert session.token_hash == token_hash(raw) and session.token_hash != raw
        codes = db.scalars(select(RecoveryCode.code_hash)).all()
        assert not set(codes).intersection(data["recovery_codes"])
    assert client.get("/api/auth/me").status_code == 200
    assert "recovery_codes" not in client.get("/api/auth/me").text


def test_duplicate_normalization(auth):
    client, _, _ = auth
    assert register(client, "Test.User").status_code == 201
    assert register(client, "test.user").status_code == 409


@pytest.mark.parametrize("username", ["ab", "a" * 31, "josé", "bad space", "Kelvin", "<bad>"])
def test_invalid_username(auth, username):
    assert register(auth[0], username).status_code == 422


@pytest.mark.parametrize("password", ["short", "a" * 7, "a" * 129])
def test_invalid_password(auth, password):
    assert register(auth[0], password=password).status_code == 422


def test_mismatch_and_long_password(auth):
    client, _, _ = auth
    assert (
        post(
            client,
            "register",
            {
                "username": "test.user",
                "password": PASSWORD,
                "password_confirmation": PASSWORD + "x",
            },
        ).status_code
        == 422
    )
    assert register(client, password="ç🔒 " * 40).status_code == 201


def test_registration_disabled(auth, monkeypatch):
    client, _, _ = auth
    monkeypatch.setenv("PUBLIC_REGISTRATION_ENABLED", "false")
    get_settings.cache_clear()
    assert client.get("/api/auth/csrf").json()["registration_enabled"] is False
    assert register(client).status_code == 403


def test_login_logout_and_protected_routes(auth):
    client, engine, _ = auth
    assert client.get("/api/auth/me").status_code == 401
    assert post(client, "recovery-codes/regenerate", {"password": PASSWORD}).status_code == 401
    register(client)
    old = client.cookies.get(SESSION_COOKIE)
    assert post(client, "logout", {}).status_code == 204
    assert client.get("/api/auth/me").status_code == 401
    with Session(engine) as db:
        assert db.scalar(
            select(LoginSession).where(LoginSession.token_hash == token_hash(old))
        ).revoked_at
    response = post(client, "login", {"username": "TEST.USER", "password": PASSWORD})
    assert response.status_code == 200
    assert client.cookies.get(SESSION_COOKIE) != old
    assert client.get("/api/auth/me").json()["user"]["username"] == "test.user"


def test_generic_invalid_login_and_no_token_in_response(auth):
    client, _, _ = auth
    register(client)
    post(client, "logout", {})
    a = post(client, "login", {"username": "test.user", "password": "wrong"})
    b = post(client, "login", {"username": "not.exists", "password": "wrong"})
    assert a.status_code == b.status_code == 401
    assert a.json() == b.json()
    assert "password" not in a.text and "token" not in a.text


def test_expired_revoked_and_inactive_sessions(auth):
    client, engine, _ = auth
    register(client)
    with Session(engine) as db:
        session = db.scalar(select(LoginSession))
        session.created_at = now() - timedelta(days=2)
        session.expires_at = now() - timedelta(days=1)
        db.commit()
    assert client.get("/api/auth/me").status_code == 401
    assert post(client, "login", {"username": "test.user", "password": PASSWORD}).status_code == 200
    with Session(engine) as db:
        user = db.scalar(select(User))
        user.is_active = False
        db.commit()
    assert client.get("/api/auth/me").status_code == 401
    assert post(client, "login", {"username": "test.user", "password": PASSWORD}).status_code == 401


def test_csrf_login_logout_origin_and_session_binding(auth):
    client, _, _ = auth
    assert (
        client.post(
            "/api/auth/login", json={"username": "test.user", "password": PASSWORD}
        ).status_code
        == 403
    )
    assert (
        post(
            client,
            "login",
            {"username": "test.user", "password": PASSWORD},
            Origin="https://evil.test",
        ).status_code
        == 403
    )
    assert (
        post(
            client,
            "login",
            {"username": "test.user", "password": PASSWORD},
            **{"Sec-Fetch-Site": "cross-site"},
        ).status_code
        == 403
    )
    old = client.get("/api/auth/csrf").json()["csrf_token"]
    register(client)
    assert (
        client.post(
            "/api/auth/logout", json={}, headers={"Origin": ORIGIN, "X-CSRF-Token": old}
        ).status_code
        == 403
    )
    assert client.get("/api/auth/me").status_code == 200
    assert post(client, "logout", {}).status_code == 204


def test_csrf_tamper(auth):
    client, _, _ = auth
    token = client.get("/api/auth/csrf").json()["csrf_token"]
    forged = token[:-1] + ("0" if token[-1] != "0" else "1")
    client.cookies.set("__Host-salvadindin_csrf", forged, domain="testserver.local", path="/")
    response = client.post(
        "/api/auth/logout", json={}, headers={"Origin": ORIGIN, "X-CSRF-Token": forged}
    )
    assert response.status_code == 403


def test_account_rate_limit_ignores_spoofed_ip(auth):
    client, _, _ = auth
    for i in range(8):
        assert (
            post(
                client,
                "login",
                {"username": "not.exists", "password": "wrong"},
                **{"X-Forwarded-For": f"192.0.2.{i}"},
            ).status_code
            == 401
        )
    response = post(client, "login", {"username": "not.exists", "password": "wrong"})
    assert response.status_code == 429 and int(response.headers["retry-after"]) > 0


def test_ip_rate_limit_across_accounts_and_clients(auth):
    client, _, app = auth
    for i in range(30):
        assert (
            post(client, "login", {"username": f"absent{i}", "password": "wrong"}).status_code
            == 401
        )
    with TestClient(app, base_url=ORIGIN) as other:
        assert post(other, "login", {"username": "another", "password": "wrong"}).status_code == 429


def test_recovery_single_use_and_all_sessions_revoked(auth):
    client, engine, app = auth
    codes = register(client).json()["recovery_codes"]
    with TestClient(app, base_url=ORIGIN) as other:
        assert (
            post(other, "login", {"username": "test.user", "password": PASSWORD}).status_code == 200
        )
        payload = {
            "username": "test.user",
            "recovery_code": codes[0],
            "password": "New isolated password 123!",
            "password_confirmation": "New isolated password 123!",
        }
        assert post(client, "recover", payload).status_code == 200
        assert client.get("/api/auth/me").status_code == 401
        assert other.get("/api/auth/me").status_code == 401
        assert post(client, "recover", payload).status_code == 400
        assert (
            post(client, "login", {"username": "test.user", "password": PASSWORD}).status_code
            == 401
        )
        assert (
            post(
                client, "login", {"username": "test.user", "password": payload["password"]}
            ).status_code
            == 200
        )
    with Session(engine) as db:
        assert (
            db.scalar(
                select(func.count())
                .select_from(RecoveryCode)
                .where(RecoveryCode.used_at.is_not(None))
            )
            == 1
        )


def test_recovery_generic_failure_and_rate_limit(auth):
    client, _, _ = auth
    register(client)
    data = {
        "username": "test.user",
        "recovery_code": "wrong",
        "password": PASSWORD,
        "password_confirmation": PASSWORD,
    }
    a = post(client, "recover", data)
    b = post(client, "recover", {**data, "username": "not.exists"})
    assert a.status_code == b.status_code == 400 and a.json() == b.json()
    for _ in range(4):
        assert post(client, "recover", data).status_code == 400
    assert post(client, "recover", data).status_code == 429


def test_regeneration_reauth_and_old_codes_invalid(auth):
    client, _, _ = auth
    old = register(client).json()["recovery_codes"]
    assert post(client, "recovery-codes/regenerate", {"password": "wrong"}).status_code == 401
    response = post(client, "recovery-codes/regenerate", {"password": PASSWORD})
    assert response.status_code == 200
    assert not set(old).intersection(response.json()["recovery_codes"])
    assert (
        post(
            client,
            "recover",
            {
                "username": "test.user",
                "recovery_code": old[0],
                "password": PASSWORD,
                "password_confirmation": PASSWORD,
            },
        ).status_code
        == 400
    )


def test_user_isolation(auth):
    client, _, app = auth
    first = register(client, "first.user").json()["user"]
    with TestClient(app, base_url=ORIGIN) as other:
        second = register(other, "second.user").json()["user"]
        assert first["id"] != second["id"]
        assert client.get("/api/auth/me").json()["user"]["id"] == first["id"]
        assert other.get("/api/auth/me").json()["user"]["id"] == second["id"]
        assert (
            client.get("/api/auth/me?user_id=" + second["id"]).json()["user"]["id"] == first["id"]
        )


def test_concurrent_duplicate_registration(auth):
    _, engine, app = auth

    def attempt(_):
        with TestClient(app, base_url=ORIGIN) as client:
            return register(client, "race.user").status_code

    with ThreadPoolExecutor(max_workers=2) as pool:
        assert sorted(pool.map(attempt, range(2))) == [201, 409]
    with Session(engine) as db:
        assert db.scalar(select(func.count()).select_from(User)) == 1


def test_concurrent_recovery_code_only_used_once(auth):
    client, _, app = auth
    code = register(client).json()["recovery_codes"][0]

    def attempt(_):
        with TestClient(app, base_url=ORIGIN) as other:
            return post(
                other,
                "recover",
                {
                    "username": "test.user",
                    "recovery_code": code,
                    "password": PASSWORD,
                    "password_confirmation": PASSWORD,
                },
            ).status_code

    with ThreadPoolExecutor(max_workers=2) as pool:
        assert sorted(pool.map(attempt, range(2))) == [200, 400]


@pytest.mark.parametrize(
    "path", ["register", "login", "logout", "recover", "recovery-codes/regenerate"]
)
def test_all_mutations_require_csrf(auth, path):
    client, _, _ = auth
    assert client.post("/api/auth/" + path, json={}).status_code == 403


def test_no_origin_and_form_content_type_rejected(auth):
    client, _, _ = auth
    token = client.get("/api/auth/csrf").json()["csrf_token"]
    assert (
        client.post("/api/auth/logout", json={}, headers={"X-CSRF-Token": token}).status_code == 403
    )
    assert (
        client.post(
            "/api/auth/logout",
            content="{}",
            headers={"Origin": ORIGIN, "X-CSRF-Token": token, "Content-Type": "text/plain"},
        ).status_code
        == 403
    )


def test_eight_character_password_registration_login_and_reset(auth):
    client, _, _ = auth
    original = "Only8!ok"
    replacement = "New8!key"
    result = register(client, password=original)
    assert result.status_code == 201
    code = result.json()["recovery_codes"][0]
    assert post(client, "logout", {}).status_code == 204
    assert post(client, "login", {"username": "test.user", "password": original}).status_code == 200
    assert (
        post(
            client,
            "recover",
            {
                "username": "test.user",
                "recovery_code": code,
                "password": replacement,
                "password_confirmation": replacement,
            },
        ).status_code
        == 200
    )
    assert client.get("/api/auth/me").status_code == 401
    assert (
        post(client, "login", {"username": "test.user", "password": replacement}).status_code == 200
    )
