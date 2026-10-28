# SalvaDinDin 2.0

Assistente financeiro pessoal gratuito, minimalista e desenvolvido em aberto.
Esta versão reconstrói a base do projeto com Python e React, de forma independente
do sistema PHP anterior. O legado permanece na branch `legacy-v1`.

## Status

Fundação técnica em `v2-development`: API com health checks, conexão PostgreSQL,
estrutura de migrações, testes e página inicial responsiva com temas sistema/claro/escuro.
Ainda não existem usuários, autenticação, dashboard ou funcionalidades financeiras.
`main` permanece preservada. Domínio futuro: `salvadindin.online`; nenhum deploy/DNS
é configurado nesta missão. A licença open source ainda está em definição.

## Tecnologias

- Python 3.12+ (container e validação: 3.14), FastAPI, Pydantic Settings, SQLAlchemy 2.
- PostgreSQL 18, Psycopg 3, Alembic; pytest e Ruff no desenvolvimento.
- Node.js 24 LTS, React 19, TypeScript 5.9, Vite 7, Tailwind CSS 4.
- Docker Compose; Nginx sem privilégios exclusivamente no container frontend.

Versões exatas Python estão em `backend/requirements*.lock`, com hashes;
as do frontend estão em `frontend/package-lock.json`. Imagens usam tags de versão;
fixação por digest poderá ser adotada na etapa de deploy.

## Estrutura

```text
backend/       API, configuração, testes e migrações Alembic
frontend/      React + TypeScript + Vite + Tailwind
infra/         Docker e verificação prévia de recursos/portas
docs/          Arquitetura e decisões da fundação
compose.yaml   Serviços isolados de desenvolvimento
.env.example   Modelo de configuração, sem credenciais reais
```

## Executar com Docker

Pré-requisitos: Docker Engine e plugin Compose instalados em um ambiente de
desenvolvimento. Não instale/inicie Docker em um host compartilhado sem avaliar
impactos de rede e firewall. A VPS desta missão ainda não tem Docker Engine.

```bash
git clone --branch v2-development git@github.com:fakersz/salvadindin.git
cd salvadindin
cp .env.example .env
# Edite .env: substitua POSTGRES_PASSWORD por uma senha aleatória exclusiva.
python3 infra/preflight.py
docker compose config --quiet
docker compose up --build -d
docker compose ps
```

Abra `http://127.0.0.1:15173`; API: `http://127.0.0.1:18000/api/health`;
documentação: `http://127.0.0.1:18000/api/docs` no modo development.
Os binds são locais. Para acessar na VPS, use túnel SSH a partir do seu computador:

```bash
ssh -L 15173:127.0.0.1:15173 -L 18000:127.0.0.1:18000 usuario@servidor
```

O PostgreSQL não publica porta no host. Dados ficam no volume exclusivo
`salvadindin_v2_postgres_data`. As portas do projeto não usam 80, 443, 5432 ou 8000
do host. Para parar apenas este projeto: `docker compose stop`. Não remova volumes.
Veja [orientações de infraestrutura](infra/README.md).

## Execução manual e testes

Use usuário de desenvolvimento sem root. Requer Python 3.12+ e Node 24.
A criação da venv depende do pacote de suporte a venv da sua distribuição.
Não conecte ao banco de produção. Configure `.env` para um PostgreSQL dedicado de
**desenvolvimento** antes de testar readiness, migrações ou funcionalidades com banco.

```bash
cd backend
python3 -m venv .venv
.venv/bin/python -m pip install --require-hashes -r requirements-dev.lock
.venv/bin/python -m pip install --no-deps -e .
.venv/bin/pytest -q
.venv/bin/ruff check .
.venv/bin/uvicorn app.main:create_app --factory --host 127.0.0.1 --port 18000
```

Os testes substituem a sessão do banco; não acessam PostgreSQL nem dados existentes.
Em outro terminal, na raiz do repositório:

```bash
cd frontend
npm ci
npm run build
npm audit --audit-level=moderate
npm run dev
```

Vite faz proxy de `/api` para o backend local. O build gera `frontend/dist`.
Para atualizar locks Python intencionalmente, use `uv pip compile pyproject.toml
--universal --generate-hashes -o requirements.lock` e repita com `--extra dev
-o requirements-dev.lock`, na pasta backend; revise e teste antes de publicar.

## Migrações

Ainda não há tabelas nem revisões. Para uma mudança futura de modelos, com banco
isolado configurado, dentro de backend:

```bash
.venv/bin/alembic revision --autogenerate -m "descrever a mudança"
# Revise o script gerado antes de aplicar.
.venv/bin/alembic upgrade head
```

No Compose, o comando equivalente é `docker compose exec backend alembic upgrade head`.
Não há execução automática de migrações ao iniciar os serviços.

## Variáveis de ambiente

| Variável | Uso |
| --- | --- |
| `APP_ENV` | `development`, `test` ou `production`; produção desativa documentação da API |
| `POSTGRES_DB`, `POSTGRES_USER` | Nome do banco e usuário exclusivos da V2 |
| `POSTGRES_PASSWORD` | Senha obrigatória no Compose; somente no `.env` local |
| `POSTGRES_HOST`, `POSTGRES_PORT` | Conexão manual; no Compose são `db:5432` |
| `DATABASE_URL` | Opcional, substitui `POSTGRES_*` no backend manual; use `postgresql+psycopg://` e codifique caracteres especiais |
| `BACKEND_PORT`, `FRONTEND_PORT` | Portas do host no Compose, padrão `18000` e `15173` |
| `API_PROXY_TARGET` | Destino do proxy Vite, padrão `http://127.0.0.1:18000` |

O backend lê `.env` da raiz quando executado em backend; variáveis do processo têm
prioridade. No Compose a senha é passada diretamente, sem concatenar uma URL.
Nunca publique `.env`, dumps, chaves privadas ou senhas. Nenhuma conformidade completa
com a LGPD foi declarada; segurança e privacidade exigem avaliação específica.

Mais detalhes em [arquitetura](docs/architecture.md).
