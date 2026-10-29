import logging
import sys

from app.core.safe_logging import SafeAccessFormatter, SafeFormatter


def test_access_preserves_diagnostics_without_query():
    record = logging.LogRecord(
        "uvicorn.access",
        logging.INFO,
        "",
        0,
        "%s %s %s %s %s",
        (
            "127.0.0.1",
            "GET",
            "/api/integrations/whatsapp/webhook?hub.verify_token=private-marker",
            "1.1",
            403,
        ),
        None,
    )
    result = SafeAccessFormatter().format(record)
    assert "private-marker" not in result and "hub.verify_token" not in result
    assert "method=GET" in result and "status=403" in result and "path=/api/" in result


def test_headers_environment_secrets_and_exception_are_not_logged(monkeypatch):
    monkeypatch.setenv("META_APP_SECRET", "environment-secret-marker")
    formatter = SafeFormatter()
    for message in [
        "environment-secret-marker",
        "Cookie: cookie-marker",
        "Authorization: Bearer auth-marker",
        "hub.verify_token=query-marker",
    ]:
        record = logging.LogRecord("app", logging.ERROR, "", 0, message, (), None)
        assert "marker" not in formatter.format(record)
    try:
        raise RuntimeError("exception-secret-marker")
    except RuntimeError:
        record = logging.LogRecord(
            "app", logging.ERROR, "", 0, "Request failed", (), sys.exc_info()
        )
    result = formatter.format(record)
    assert "exception-secret-marker" not in result and "RuntimeError" in result
