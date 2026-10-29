import re
import uuid
from decimal import Decimal
from typing import Annotated, Literal

from pydantic import AwareDatetime, BaseModel, BeforeValidator, ConfigDict, Field, field_validator


def exact_money(value):
    if isinstance(value, bool) or not isinstance(value, str | int | Decimal):
        raise ValueError("Envie dinheiro como texto decimal, por exemplo 123.45.")
    raw = str(value)
    if not re.fullmatch(r"-?(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?", raw):
        raise ValueError("Valor inválido: use até duas casas decimais.")
    return Decimal(raw)


Money = Annotated[Decimal, BeforeValidator(exact_money), Field(max_digits=18, decimal_places=2)]
PositiveMoney = Annotated[Money, Field(gt=0)]
AccountType = Literal["checking", "savings", "cash", "digital_wallet"]
Kind = Literal["income", "expense"]
TransactionKind = Literal["income", "expense", "transfer"]
Name = Annotated[str, Field(strict=True, min_length=1, max_length=60)]


class Input(BaseModel):
    model_config = ConfigDict(extra="forbid")

    @field_validator("*", mode="before")
    @classmethod
    def strip_text(cls, value, info):
        if info.field_name in {"name", "description", "icon"} and isinstance(value, str):
            return value.strip()
        return value


class AccountCreate(Input):
    name: Name
    type: AccountType
    initial_balance: Money = Decimal("0.00")
    currency: Literal["BRL"] = "BRL"


class AccountPatch(Input):
    name: Name | None = None
    type: AccountType | None = None
    initial_balance: Money | None = None
    is_active: bool | None = None


class CategoryCreate(Input):
    name: Name
    type: Kind
    icon: str | None = Field(default=None, max_length=16)


class CategoryPatch(Input):
    name: Name | None = None
    type: Kind | None = None
    icon: str | None = Field(default=None, max_length=16)


class TransactionCreate(Input):
    account_id: uuid.UUID
    destination_account_id: uuid.UUID | None = None
    category_id: uuid.UUID | None = None
    type: TransactionKind
    amount: PositiveMoney
    description: str = Field(min_length=1, max_length=240)
    occurred_at: AwareDatetime


class TransactionPatch(Input):
    account_id: uuid.UUID | None = None
    destination_account_id: uuid.UUID | None = None
    category_id: uuid.UUID | None = None
    type: TransactionKind | None = None
    amount: PositiveMoney | None = None
    description: str | None = Field(default=None, min_length=1, max_length=240)
    occurred_at: AwareDatetime | None = None


class Confirm(Input):
    confirm: Literal[True]
