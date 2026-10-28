import pytest
from pydantic import ValidationError

from app.core.config import Settings, get_settings
from app.main import create_app


def test_password_special_characters_are_preserved(monkeypatch):
    monkeypatch.delenv("DATABASE_URL")
    password = "local@only:/?#%"
    settings = Settings(_env_file=None, postgres_password=password)
    assert settings.sqlalchemy_url.drivername == "postgresql+psycopg"
    assert settings.sqlalchemy_url.password == password
    assert password not in repr(settings)
    assert password not in str(settings.sqlalchemy_url)


def test_database_credentials_are_required(monkeypatch):
    monkeypatch.delenv("DATABASE_URL")
    monkeypatch.delenv("POSTGRES_PASSWORD", raising=False)
    with pytest.raises(ValidationError):
        Settings(_env_file=None)


def test_unix_socket_is_passed_to_postgres_driver(monkeypatch):
    from sqlalchemy.dialects.postgresql.psycopg import PGDialect_psycopg

    monkeypatch.delenv("DATABASE_URL")
    settings = Settings(
        _env_file=None,
        postgres_password="test-only-password",
        postgres_user="salvadindin_app",
        postgres_socket_dir="/run/postgresql",
    )
    _, parameters = PGDialect_psycopg().create_connect_args(settings.sqlalchemy_url)
    assert parameters["host"] == "/run/postgresql"
    assert parameters["dbname"] == "salvadindin_v2"
    assert parameters["user"] == "salvadindin_app"


def test_production_disables_api_documentation(monkeypatch):
    monkeypatch.setenv("APP_ENV", "production")
    get_settings.cache_clear()
    app = create_app()
    assert app.docs_url is None
    assert app.openapi_url is None
    get_settings.cache_clear()
