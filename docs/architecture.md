# Fundação V2

A V2 é independente do legado PHP preservado em `legacy-v1`; `main` não é
alterada. Não importe o schema SQL antigo na V2.

```text
HTTPS :443 → Nginx do host
                ├─ /       → 127.0.0.1:15173 → frontend React estático
                └─ /api/   → 127.0.0.1:18000 → FastAPI
                                                   └─ socket Unix + SCRAM
                                                      PostgreSQL 18 do host
                                                      banco salvadindin_v2
```

O frontend e o backend são containers sem root, com limites de recursos e
rede exclusiva. O PostgreSQL não está em container nem exposto à internet.
Só o backend recebe o bind somente leitura do diretório de socket. A role
`salvadindin_app` autentica apenas no banco do projeto por regras HBA específicas.
As credenciais ficam em arquivo root-only fora do repositório.

- `backend/app/api`: rotas HTTP sob `/api`.
- `backend/app/core`: configuração tipada e envelope de erros.
- `backend/app/db`: SQLAlchemy 2, engine com pool_pre_ping e pool limitado.
- `backend/app/auth`: contas, sessões, CSRF, limites persistentes e recuperação.
- `backend/migrations`: Alembic; revisões `0001_auth` e `0002_finance` para acesso e núcleo financeiro.
- `backend/app/finance`: contas, categorias, movimentações e resumo com escopo por usuário.
- `frontend/src`: React, Tailwind 4 e temas sistema/claro/escuro.
- `infra`: configuração de implantação e orientações operacionais.

O frontend usa caminhos relativos `/api`; Vite fornece proxy no desenvolvimento,
e o Nginx do host roteia a API na implantação. O Nginx do container também fornece
proxy local para verificações internas. Nenhum segredo é enviado no bundle.
Variáveis `VITE_*` são públicas e não devem receber credenciais.

`GET /api/health` verifica o processo sem consultar banco.
`GET /api/health/ready` executa `SELECT 1` e retorna 503 em falha.
Os health checks verificam readiness do backend e HTTP do frontend.

Erros usam `{"error": {"code": "...", "message": "..."}}`, sem detalhes internos.
`APP_ENV=production` desativa documentação da API. Migrações são explícitas;
nenhuma criação de tabelas ocorre no startup. A revisão de autenticação é `0001_auth`. As tabelas têm UUIDs, timestamps com
timezone, constraints e índices. Tokens de sessão e códigos só persistem como
hashes. A dependência `require_auth` deve proteger as futuras rotas financeiras.

Autenticação e núcleo financeiro estão implementados. Saldos derivados e
transferências com origem/destino são descritos em [finance.md](finance.md). O cadastro público é controlado por variável de ambiente.
CI, monitoramento, backups recorrentes, restauração comprovada e revisão de
segurança/privacidade continuam pendentes. Não há declaração de conformidade
LGPD nem licença open source escolhida.
