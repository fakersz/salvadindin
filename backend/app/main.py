from fastapi import FastAPI

from app.api.health import router
from app.auth.routes import router as auth_router
from app.core.config import get_settings
from app.core.errors import register_error_handlers
from app.finance.routes import router as finance_router


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
    app.include_router(auth_router)
    app.include_router(finance_router)

    @app.middleware("http")
    async def auth_no_cache(request, call_next):
        response = await call_next(request)
        if request.url.path.startswith("/api/") and not request.url.path.startswith("/api/health"):
            response.headers["Cache-Control"] = "no-store"
            response.headers["Pragma"] = "no-cache"
        return response

    return app
