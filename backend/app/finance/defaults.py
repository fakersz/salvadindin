import uuid

from sqlalchemy.dialects.postgresql import insert

from app.auth.security import now
from app.finance.models import Category

DEFAULTS = [
    ("Salário", "income"),
    ("Outras receitas", "income"),
    ("Alimentação", "expense"),
    ("Moradia", "expense"),
    ("Transporte", "expense"),
    ("Saúde", "expense"),
    ("Outras despesas", "expense"),
]


def seed_categories(db, user_id):
    for name, kind in DEFAULTS:
        db.execute(
            insert(Category)
            .values(
                id=uuid.uuid4(),
                user_id=user_id,
                name=name,
                type=kind,
                created_at=now(),
                updated_at=now(),
            )
            .on_conflict_do_nothing()
        )
