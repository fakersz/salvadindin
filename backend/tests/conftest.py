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
