# Fundação V2

A V2 é independente do PHP. O código anterior e seu histórico permanecem em
`legacy-v1`; `main` não foi alterada. Não importe o schema SQL antigo na V2.

- `backend/app/api`: rotas HTTP sob `/api`.
- `backend/app/core`: configuração tipada e envelope de erros.
- `backend/app/db`: base declarativa SQLAlchemy 2, engine com pool_pre_ping e sessões.
- `backend/app/auth`: reserva para trabalho futuro, sem autenticação implementada.
- `backend/migrations`: ambiente Alembic; ainda não existem tabelas/revisões.
- `frontend/src`: aplicação React, estilos Tailwind 4 e tokens de tema.
- `infra`: instruções e verificação de portas/recursos do ambiente de desenvolvimento.

O frontend usa caminhos relativos `/api`; Vite faz proxy durante desenvolvimento,
e o Nginx do container faz proxy no Compose. Nenhum segredo vai para o bundle.
Variáveis `VITE_*` seriam públicas e não devem receber credenciais.

`GET /api/health` verifica o processo sem acessar o banco.
`GET /api/health/ready` executa `SELECT 1` e retorna 503 se o banco estiver indisponível.
Health checks do Compose usam readiness para o backend e disponibilidade HTTP
para o frontend; o banco usa pg_isready.

Erros usam `{"error": {"code": "...", "message": "..."}}` sem expor detalhes
internos ou entradas recebidas. Configuração exige credenciais explícitas.
DATABASE_URL é opcional para execução manual; no Compose, os componentes
POSTGRES_* constroem a conexão sem problemas com caracteres especiais na senha.

Migrações são uma operação explícita. Com modelos futuros, importe-os em
`backend/migrations/env.py` antes de executar autogenerate. Nenhuma criação de
tabelas ocorre no startup; nenhum acesso ao banco é necessário para testar liveness.

Ficam para próximas missões: autenticação, usuários, entidades financeiras,
testes de integração com PostgreSQL isolado, execução dos containers, CI,
deploy, HTTPS e revisão de segurança/privacidade. Não há avaliação de conformidade LGPD.
A licença open source ainda precisa ser escolhida; nenhuma licença foi presumida.
