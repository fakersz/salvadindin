from fastapi import FastAPI

from app.api.health import router
from app.core.config import get_settings
from app.core.errors import register_error_handlers


def create_app() -> FastAPI:
    settings = get_settings()
    app = FastAPI(
        title="SalvaDinDin API",
        version="2.0.0.dev0",
        debug=False,
        docs_url="/api/docs" if settings.app_env != "production" else None,
        redoc_url=None,
        openapi_url="/api/openapi.json" if settings.app_env != "production" else None,
    )
    register_error_handlers(app)
    app.include_router(router)
    return app
