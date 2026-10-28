import logging

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException

logger = logging.getLogger(__name__)


def error_response(status: int, code: str, message: str, headers=None) -> JSONResponse:
    return JSONResponse(
        status_code=status,
        content={"error": {"code": code, "message": message}},
        headers=headers,
    )


def register_error_handlers(app: FastAPI) -> None:
    @app.exception_handler(HTTPException)
    async def http_error(request: Request, exc: HTTPException):
        # Avoid reflecting arbitrary exception details or request input.
        return error_response(
            exc.status_code,
            f"http_{exc.status_code}",
            "Recurso não encontrado."
            if exc.status_code == 404
            else "Não foi possível concluir a solicitação.",
            exc.headers,
        )

    @app.exception_handler(RequestValidationError)
    async def validation_error(request: Request, exc: RequestValidationError):
        return error_response(422, "validation_error", "Dados de entrada inválidos.")

    @app.exception_handler(Exception)
    async def unexpected_error(request: Request, exc: Exception):
        # Do not log exception text: database errors may contain connection credentials.
        logger.error("Unhandled exception type=%s path=%s", type(exc).__name__, request.url.path)
        return error_response(500, "internal_error", "Erro interno do servidor.")
