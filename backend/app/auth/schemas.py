import re
from typing import Annotated

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

Password = Annotated[str, Field(strict=True, min_length=12, max_length=128)]


class Credentials(BaseModel):
    model_config = ConfigDict(extra="forbid")
    username: str = Field(strict=True, min_length=3, max_length=32)
    password: str = Field(strict=True, min_length=1, max_length=128)

    @field_validator("username")
    @classmethod
    def normalize_username(cls, value: str) -> str:
        value = value.strip()
        if re.fullmatch(r"[A-Za-z0-9_.-]{3,30}", value) is None:
            raise ValueError("invalid_username")
        return value.lower()


class Registration(Credentials):
    password: Password
    password_confirmation: Password

    @model_validator(mode="after")
    def passwords_match(self):
        if self.password != self.password_confirmation:
            raise ValueError("passwords_do_not_match")
        return self


class Recovery(Registration):
    recovery_code: str = Field(strict=True, min_length=1, max_length=80)


class Reauthenticate(BaseModel):
    model_config = ConfigDict(extra="forbid")
    password: str = Field(strict=True, min_length=1, max_length=128)
