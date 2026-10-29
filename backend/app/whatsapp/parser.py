"""Conservative Brazilian text grammar; ambiguous values are never guessed."""

import re
import unicodedata
from dataclasses import dataclass
from decimal import Decimal


@dataclass(frozen=True)
class Draft:
    kind: str
    amount: Decimal
    description: str
    account_hint: str | None
    category_name: str | None


def normalize(value):
    return "".join(
        c for c in unicodedata.normalize("NFKD", value.casefold()) if not unicodedata.combining(c)
    )


def parse(text: str) -> Draft | None:
    if len(text) > 500:
        return None
    match = re.fullmatch(
        r"\s*(gastei|paguei|recebi|entrada|despesa)\s+(?:R\$\s*)?([0-9.,]+)\s+(.+?)\s*",
        text,
        re.IGNORECASE,
    )
    if not match:
        return None
    verb, amount, description = match.groups()
    # BRL comma decimals, optional thousands separators. Dot-only cents are ambiguous.
    if not re.fullmatch(
        r"(?:0|[1-9][0-9]*|[1-9][0-9]{0,2}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?", amount
    ):
        return None
    amount = Decimal(amount.replace(".", "").replace(",", "."))
    if not 0 < amount <= Decimal("9999999999999999.99"):
        return None
    hint = None
    parts = re.split(r"\s+na conta\s+", description, flags=re.IGNORECASE)
    if len(parts) > 2:
        return None
    if len(parts) == 2:
        description, hint = parts
        if not hint.strip():
            return None
        hint = hint.strip()
    description = re.sub(r"^(?:no|na|de|do|da)\s+", "", description.strip(), flags=re.IGNORECASE)
    clean = normalize(description)
    # A second amount, transfer/payment instructions, dates or conjunctions need clarification.
    if (
        not description
        or len(description) > 240
        or re.search(r"\d", description)
        or re.search(
            r"\b(e|ou|amanha|ontem|transferir|transferencia|pagar|agendar|dolar|dolares|euro|euros|usd|eur)\b",
            clean,
        )
    ):
        return None
    kind = "income" if verb.casefold() in {"recebi", "entrada"} else "expense"
    rules = (
        {"Salário": {"salario"}}
        if kind == "income"
        else {
            "Alimentação": {"mercado", "supermercado", "almoco", "jantar", "lanche"},
            "Transporte": {"gasolina", "combustivel", "uber", "onibus"},
            "Moradia": {"aluguel", "condominio"},
            "Saúde": {"farmacia", "remedio", "consulta"},
        }
    )
    words = set(re.findall(r"\w+", clean))
    suggestions = [name for name, keys in rules.items() if words & keys]
    return Draft(kind, amount, description, hint, suggestions[0] if len(suggestions) == 1 else None)
