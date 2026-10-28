# SalvaDinDin 2.0

Assistente financeiro pessoal gratuito e minimalista, desenvolvido em aberto.
A fundação está publicada em **https://salvadindin.online** e
**https://www.salvadindin.online**. Cadastro, login, sessões e recuperação por
códigos estão implementados. O cadastro público começa desativado por variável
de ambiente. Ainda não há dashboard ou funcionalidades financeiras.

O desenvolvimento acontece em `v2-development`; `main` e `legacy-v1` permanecem
preservadas. A licença open source ainda está em definição.

## Arquitetura em execução

- Nginx do host: HTTPS, redirecionamento HTTP e proxy por domínio.
- Frontend: React + TypeScript + Vite + Tailwind; build estático servido por
  Nginx sem root no container `salvadindin-frontend`.
- Backend: FastAPI + SQLAlchemy + Psycopg, container `salvadindin-backend` sem root.
- PostgreSQL 18 no host; banco `salvadindin_v2`, role `salvadindin_app` sem
  SUPERUSER, CREATEDB, CREATEROLE, REPLICATION ou BYPASSRLS.
- Somente o backend recebe o diretório do socket PostgreSQL, em bind somente
  leitura. Autenticação SCRAM e regras HBA específicas restringem essa role ao
  banco do projeto por socket. Não existe container PostgreSQL.

As portas são `127.0.0.1:15173` (frontend) e `127.0.0.1:18000` (backend).
O PostgreSQL continua em localhost:5432 e não é publicado pelos containers.
A rede exclusiva é `salvadindin_v2_application` (`172.30.10.0/24`).

## Estrutura

```text
backend/       API, testes, configuração e migrações Alembic
frontend/      React + TypeScript + Vite + Tailwind
infra/         Templates Nginx, Docker, HBA e orientações operacionais
docs/          Arquitetura e registros de validação
compose.yaml   Dois serviços de produção, sem banco em container
.env.example   Exemplo sem credenciais reais
```

## Operação na VPS preparada

Execute os comandos administrativos na raiz do checkout. O usuário de
projeto não recebe acesso automático ao grupo Docker, equivalente a root.

```bash
sudo docker compose config --quiet
sudo docker compose build
sudo docker compose run --rm --no-deps backend alembic upgrade head
sudo docker compose up -d --wait
sudo docker compose ps
curl --fail https://salvadindin.online/api/health
curl --fail https://salvadindin.online/api/health/ready
```

O Compose lê as credenciais de `/etc/salvadindin/backend.env`, fora do Git,
com diretório root:root 0700 e arquivo 0600. Não exiba `docker compose config`
sem `--quiet`, nem o ambiente completo dos containers: podem revelar segredos.
O caminho pode ser substituído com `SALVADINDIN_ENV_FILE`.

A execução pressupõe banco, role, regras HBA, socket e rede preparados conforme
[infra/README.md](infra/README.md). Não instale ou configure serviços de outro
projeto seguindo este README. Não aponte o backend ou Alembic para outro banco.

Os limites somam 512 MiB e 0,75 CPU; builds precisam de margem adicional.
Os containers usam `unless-stopped`, rootfs somente leitura, tmpfs, remoção de
capabilities, limites de processos e logs com rotação (3 arquivos de 10 MiB).
Não há reinício automático em resposta apenas a um health check malsucedido.

## Desenvolvimento e testes

Python 3.12+ e Node 24. Dependências estão fixadas em locks Python com hashes
e em `frontend/package-lock.json`. Use um ambiente de desenvolvimento separado.
Não use as credenciais de produção nos testes.

```bash
cd backend
python3 -m venv .venv
.venv/bin/python -m pip install --require-hashes -r requirements-dev.lock
.venv/bin/python -m pip install --no-deps -e .
.venv/bin/pytest -q
.venv/bin/ruff check .
.venv/bin/ruff format --check .
```

Os testes substituem o acesso ao banco e usam uma conexão de teste inválida
para impedir acesso acidental ao banco do host.

```bash
cd frontend
npm ci
npm run build
npm audit --audit-level=moderate
```

Para execução manual, configure `POSTGRES_HOST=127.0.0.1`, banco/usuário/senha
exclusivos de desenvolvimento e omita `POSTGRES_SOCKET_DIR`. O Vite encaminha
`/api` para `http://127.0.0.1:18000`, ajustável por `API_PROXY_TARGET`.
`DATABASE_URL` substitui os componentes de conexão quando informado; não o
combine com a configuração de socket esperando que esta tenha prioridade.

## Autenticação

Páginas `/login`, `/register`, `/recover` e `/app`. Senhas Argon2id; sessões
opacas em cookies HttpOnly/Secure/SameSite=Lax, revogáveis no banco. Sem tokens
em localStorage, e-mail ou telefone. Códigos de recuperação de uso único são
exibidos uma vez e devem ser guardados pelo usuário.

Configure `AUTH_SECRET` aleatório com pelo menos 32 caracteres no arquivo
privado e mantenha `PUBLIC_REGISTRATION_ENABLED=false` durante a validação
inicial. A ativação é uma decisão operacional explícita: alterar somente essa
variável para `true` e recriar apenas o backend com `docker compose up -d
--no-deps backend`. O endpoint `/api/auth/csrf` informa o estado público do cadastro.
Nunca inclua credenciais ou códigos em comandos de terminal, logs ou Git.

Veja [autenticação e testes isolados](docs/authentication.md).

## Migrações e API

`/api/health` verifica o processo; `/api/health/ready` executa `SELECT 1`.
`APP_ENV=production` desativa `/api/docs` e `/api/openapi.json`.
A API não cria tabelas automaticamente ao iniciar.

A revisão `0001_auth` cria users, sessions, recovery_codes e auth_rate_limits.
Migrações são aplicadas exclusivamente no banco SalvaDinDin; os testes usam
outra instância PostgreSQL descartável, sem acesso ao cluster do host.
Mudanças futuras de modelo exigem revisão explícita antes de `upgrade head`.

HTTPS usa Let's Encrypt com renovação pelo timer Certbot e hook exclusivo que
valida Nginx antes do reload. Não há HSTS preload.

Consulte [arquitetura](docs/architecture.md), [validação inicial](docs/validation.md)
e [validação da publicação](docs/deployment.md). Segurança e privacidade exigem
avaliação contínua; não há declaração de conformidade completa com a LGPD.
