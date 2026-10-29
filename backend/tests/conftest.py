import os

import pytest
from fastapi.testclient import TestClient

# No test should touch the host database, even if a developer has a real .env.
os.environ["APP_ENV"] = "test"
os.environ["AUTH_SECRET"] = "isolated-tests-only-not-a-production-secret"
os.environ["DATABASE_URL"] = "postgresql+psycopg://test:test@127.0.0.1:1/test"

from app.core.config import get_settings  # noqa: E402
from app.main import create_app  # noqa: E402


@pytest.fixture
def app():
    get_settings.cache_clear()
    return create_app()


@pytest.fixture
def client(app):
    with TestClient(app, raise_server_exceptions=False) as client:
        yield client


from sqlalchemy import create_engine, delete, func, select  # noqa: E402
from sqlalchemy.engine import make_url  # noqa: E402
from sqlalchemy.orm import Session  # noqa: E402

from app.db.base import Base  # noqa: E402
from app.db.session import get_session  # noqa: E402

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
