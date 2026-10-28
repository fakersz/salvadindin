from typing import Annotated

from fastapi import APIRouter, Depends
from sqlalchemy import text
from sqlalchemy.exc import SQLAlchemyError
from sqlalchemy.orm import Session

from app.core.errors import error_response
from app.db.session import get_session

router = APIRouter(prefix="/api", tags=["health"])


@router.get("/health")
def health():
    """Liveness: the process is responding; no database access."""
    return {"status": "ok", "service": "salvadindin-api", "version": "2.0.0.dev0"}


@router.get("/health/ready", responses={503: {"description": "Database unavailable"}})
def readiness(session: Annotated[Session, Depends(get_session)]):
    """Readiness: additionally verify the configured database."""
    try:
        session.execute(text("SELECT 1"))
    except SQLAlchemyError:
        return error_response(503, "database_unavailable", "Banco de dados indisponível.")
    return {"status": "ok", "database": "ok"}
