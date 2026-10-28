# Validação da Missão 02 — 2026-10-28

Ambiente: Ubuntu 26.04.1, desenvolvimento como `ftpjean` em `/opt/salvadindin`.
Python 3.14.4; Node 24.22.1 e npm 11.19.1 disponíveis em cópia isolada no home do
usuário, sem substituir runtimes de outras aplicações.

## Versões resolvidas

| Componente | Versão |
| --- | --- |
| FastAPI | 0.162.0 |
| SQLAlchemy | 2.0.55 |
| Alembic | 1.20.1 |
| Psycopg | 3.3.6 |
| Pydantic Settings | 2.16.2 |
| Uvicorn | 0.54.0 |
| pytest | 9.2.0 |
| React / React DOM | 19.3.1 |
| TypeScript | 5.9.3 |
| Vite | 7.3.7 |
| Tailwind CSS | 4.3.3 |
| Compose standalone (somente validação) | 5.6.1 |
| Gitleaks | 8.30.1 |

Locks Python com hashes e npm lock versionados. Ferramentas de verificação
ficam fora do repositório ou em `.venv` ignorada.

## Resultados

- pytest: 10 testes aprovados, sem avisos; liveness, readiness com banco simulado,
  falha do banco sem vazamento, erros HTTP/validação/internos, configuração e docs de produção.
- Ruff: lint e formatação aprovados.
- `pip check`: dependências consistentes.
- Alembic `upgrade head --sql`: aprovado em modo offline, sem acessar banco.
  Ainda não há migrações/tabelas.
- `npm ci` seguido de `npm run build`: aprovado; TypeScript e bundle Vite válidos.
- `npm audit --audit-level=moderate`: nenhuma vulnerabilidade reportada.
- HTTP real: `/api/health` e página inicial retornaram 200 em processos locais
  temporários; ambos encerrados após a verificação.
- Compose `config --quiet`: aprovado; configuração normalizada inspecionada
  para confirmar binds locais, banco sem porta publicada e volume/rede exclusivos.
- Preflight: portas 18000 e 15173 livres; cerca de 39 GiB livres em disco e
  2 GiB de memória disponíveis no momento da validação.
- Histórico e alterações preparadas verificados com Gitleaks em modo redigido;
  nenhum segredo encontrado. Isso não substitui uma auditoria completa de segurança.
- Nginx, PostgreSQL e `anninha15.service`: processos e horários de inicialização
  iguais antes/depois; portas existentes preservadas.

## Limitações e próximos passos

Nenhum container foi iniciado. Docker Engine não está instalado, e instalá-lo
poderia alterar rede/firewall do host compartilhado. Os Dockerfiles e o Compose
precisam de build e teste de integração em ambiente com Docker antes de deploy.
Não foi feito teste visual em navegador nem teste com PostgreSQL real nesta missão.
Nenhum banco existente foi acessado/modificado; nenhum serviço foi reiniciado.

Configuração é de desenvolvimento, sem credenciais reais, DNS, TLS ou deploy.
Definir licença, revisar privacidade/segurança e executar a infraestrutura isolada
são tarefas futuras. Não há declaração de conformidade LGPD.
