from unittest.mock import Mock

from fastapi import HTTPException
from sqlalchemy.exc import OperationalError

from app.db.session import get_session


def test_health_is_alive_without_database(client):
    response = client.get("/api/health")
    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "service": "salvadindin-api",
        "version": "2.0.0.dev0",
    }


def test_readiness_success(app, client):
    session = Mock()
    app.dependency_overrides[get_session] = lambda: session
    response = client.get("/api/health/ready")
    assert response.status_code == 200
    assert response.json() == {"status": "ok", "database": "ok"}
    assert str(session.execute.call_args.args[0]) == "SELECT 1"


def test_readiness_failure_does_not_leak_credentials(app, client):
    session = Mock()
    session.execute.side_effect = OperationalError("secret-password", {}, Exception("private"))
    app.dependency_overrides[get_session] = lambda: session
    response = client.get("/api/health/ready")
    assert response.status_code == 503
    assert response.json()["error"]["code"] == "database_unavailable"
    assert "secret-password" not in response.text


def test_unknown_route_uses_error_envelope(client):
    response = client.get("/missing")
    assert response.status_code == 404
    assert response.json()["error"]["code"] == "http_404"


def test_unexpected_error_hides_details(app, client):
    @app.get("/test-error")
    def fail():
        raise RuntimeError("secret-password")

    response = client.get("/test-error")
    assert response.status_code == 500
    assert response.json()["error"]["code"] == "internal_error"
    assert "secret-password" not in response.text


def test_validation_error_hides_input(app, client):
    @app.get("/test-validation")
    def validate(count: int):
        return count

    response = client.get("/test-validation?count=secret-password")
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "validation_error"
    assert "secret-password" not in response.text


def test_http_error_preserves_headers(app, client):
    @app.get("/test-http")
    def fail():
        raise HTTPException(401, "private", headers={"WWW-Authenticate": "Bearer"})

    response = client.get("/test-http")
    assert response.status_code == 401
    assert response.headers["www-authenticate"] == "Bearer"
    assert "private" not in response.text
