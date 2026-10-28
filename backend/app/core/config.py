from functools import lru_cache
from typing import Literal

from pydantic import Field, PostgresDsn, SecretStr, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict
from sqlalchemy.engine import URL, make_url


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=("../.env", ".env"),
        env_file_encoding="utf-8",
        extra="ignore",
        hide_input_in_errors=True,
    )
    app_env: Literal["development", "test", "production"] = "development"
    database_url: PostgresDsn | None = None
    auth_secret: SecretStr | None = None
    public_registration_enabled: bool = False
    auth_allowed_origins: list[str] = [
        "https://salvadindin.online",
        "https://www.salvadindin.online",
    ]
    session_hours: int = Field(default=168, ge=1, le=720)
    auth_rate_limit_multiplier: int = Field(default=1, ge=1, le=10)
    postgres_host: str = "127.0.0.1"
    postgres_socket_dir: str | None = None
    postgres_port: int = 5432
    postgres_db: str = "salvadindin_v2"
    postgres_user: str = "salvadindin"
    postgres_password: SecretStr | None = None

    @model_validator(mode="after")
    def require_database_credentials(self):
        if self.database_url is None and (
            self.postgres_password is None or not self.postgres_password.get_secret_value()
        ):
            raise ValueError("Configure DATABASE_URL ou POSTGRES_PASSWORD.")
        if self.app_env == "production" and (
            self.auth_secret is None or len(self.auth_secret.get_secret_value()) < 32
        ):
            raise ValueError("Configure AUTH_SECRET com pelo menos 32 caracteres.")
        return self

    @property
    def sqlalchemy_url(self) -> URL:
        if self.database_url is not None:
            return make_url(str(self.database_url))
        return URL.create(
            "postgresql+psycopg",
            username=self.postgres_user,
            password=self.postgres_password.get_secret_value(),
            host=None if self.postgres_socket_dir else self.postgres_host,
            port=self.postgres_port,
            database=self.postgres_db,
            query={"host": self.postgres_socket_dir} if self.postgres_socket_dir else {},
        )


@lru_cache
def get_settings() -> Settings:
    return Settings()
