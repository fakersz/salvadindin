# Núcleo financeiro — Missão 05

## Funcionalidades

Páginas autenticadas `/app`, `/app/accounts`, `/app/transactions`, `/app/categories`
e `/app/security`. O dashboard mostra saldo atual, receitas/despesas/resultado do
mês, contas, últimas movimentações e distribuição das despesas por categoria.
Novos usuários começam sem contas ou movimentações; categorias iniciais são
criadas idempotentemente. Não há valores de demonstração em produção.

Contas aceitam conta corrente, poupança, dinheiro e carteira digital, com saldo
inicial editável. Arquivamento é lógico: preserva saldo e histórico, bloqueando
novas movimentações e alterações/exclusões de movimentações vinculadas. Reative
antes de modificá-las. Saldo negativo é permitido e destacado visualmente.

Receitas, despesas e transferências podem ser criadas e editadas. Exclusões
exigem confirmação explícita. Categorias personalizadas podem ser renomeadas;
exclusão e alteração de tipo são bloqueadas quando violariam vínculos existentes.

## Modelagem e exatidão

Revisão Alembic `0002_finance` adiciona `financial_accounts`, `categories` e
`transactions`, sem alterar ou apagar usuários, sessões ou registros existentes.
UUIDs, timestamps com timezone, índices de usuário/data/conta/categoria e checks
protegem os dados. FKs compostas incluem user_id; a referência à categoria inclui
também o tipo. Isso impede vínculos entre usuários ou com categoria de tipo errado
inclusive quando a API é contornada e um INSERT inválido chega ao PostgreSQL.

Uma transferência é **um único registro lógico**: `account_id` é a origem e
`destination_account_id` o destino. Checks exigem contas distintas e categoria
nula; receitas/despesas não podem ter destino. O valor sempre é positivo e o tipo
é explícito. A escrita, edição ou exclusão dessa linha é atômica e afeta as duas
pontas do saldo calculado. Transferências não entram em receitas/despesas mensais.

Saldo atual = saldo inicial + receitas - despesas + transferências recebidas -
transferências enviadas, considerando apenas movimentações até o instante atual.
O saldo consolidado inclui contas arquivadas e cancela as duas pontas de cada
transferência. Não há coluna de saldo mutável, cache financeiro ou atualização
incremental que possa perder centavos ou ficar desatualizada após edição/exclusão.

Valores são NUMERIC(18,2) no banco e Decimal no Python. Na API são recebidos como
**texto decimal com ponto** (inteiros exatos também são aceitos), rejeitando float,
exponentes, valores não finitos e mais de duas casas. Respostas monetárias são
sempre strings decimais. O navegador usa strings e BigInt para formatação e
percentuais, sem converter dinheiro para Number. A interface recebe valores em
formato brasileiro, com vírgula decimal e ponto opcional de milhar. O saldo inicial
pode ser negativo ou zero; o valor das movimentações deve ser estritamente positivo.

## Concorrência e autorização

Toda rota financeira reutiliza `require_auth`. Consultas e agregações são sempre
limitadas ao proprietário. Referências e filtros de outra conta/categoria retornam
404 sem revelar sua existência. Payloads não aceitam user_id ou campos extras.

Mutações usam o CSRF existente e travam a linha do usuário com FOR UPDATE antes de
validar referências e estado de contas. Isso serializa operações financeiras do
mesmo usuário, inclusive corridas entre arquivamento/escrita e exclusão de categoria/
movimentação. Não serializa usuários diferentes. Leitores usam FOR SHARE na mesma
linha, mantendo as várias consultas de resumo/histórico consistentes entre si.
A sessão é revalidada após obter a trava. As transações terminam por commit ou
rollback; nenhum lock de aplicação vive fora da transação PostgreSQL.

As respostas financeiras usam Cache-Control: no-store. Erros de validação e
integridade não incluem SQL, dados recebidos, credenciais ou registros financeiros.

## Datas e resumo mensal

`occurred_at` exige timezone explícito. O formulário converte o horário do
dispositivo para ISO com fuso; histórico e resumos usam America/Sao_Paulo. Filtros
por dia são inclusivos, implementados como início do dia e início do dia seguinte,
sem truncar horário no banco. Intervalos inválidos são rejeitados.

O mês do resumo é um calendário brasileiro (`YYYY-MM`). Saldo atual é independente
do mês escolhido. Fluxo mensal e últimas movimentações incluem somente registros
realizados até agora; registros futuros ficam visíveis no histórico como agendados
mas não entram no saldo atual nem no fluxo realizado. Alterá-los para uma data
passada reflete imediatamente no saldo calculado. Saldo inicial é uma base da conta,
não uma receita mensal e não tem data de competência própria.

## API autenticada

| Método | Endpoint | Operação |
| --- | --- | --- |
| GET, POST | `/api/accounts` | Listar saldos / criar conta |
| GET, PATCH | `/api/accounts/{id}` | Consultar / editar ou reativar |
| DELETE | `/api/accounts/{id}` | Arquivar, preservando histórico |
| GET, POST | `/api/categories` | Listar / criar categoria |
| PATCH, DELETE | `/api/categories/{id}` | Editar / excluir se não usada |
| GET, POST | `/api/transactions` | Histórico paginado / criar receita, despesa ou transferência |
| GET, PATCH, DELETE | `/api/transactions/{id}` | Consultar / editar / excluir |
| GET | `/api/dashboard/summary` | Saldo e resumo mensal |

DELETE exige corpo JSON `{"confirm":true}` e CSRF, inclusive para arquivamento.
PATCH aceita apenas os campos fornecidos; campos obrigatórios não aceitam null.
Para converter uma transferência em receita/despesa, limpe explicitamente o destino;
para converter em transferência, informe destino e limpe categoria.

Histórico: filtros `start_date`, `end_date`, `type`, `account_id`, `category_id`,
`page` (1 até 1.000.000) e `per_page` (1 até 100; padrão 20). Filtro por conta também
inclui transferências recebidas. Ordenação estável por occurred_at, created_at e UUID,
todos descendentes. Resposta contém items, total, page e per_page. O resumo aceita
`month=YYYY-MM`. Nomes de categorias são únicos por usuário/tipo sem distinção de caixa.

## Migração, testes e publicação

O seed da migração cobre usuários existentes; novos cadastros recebem categorias
na mesma transação da conta de acesso. Uma passagem idempotente após atualizar o
backend cobre cadastros concluídos pelo container antigo durante a janela de deploy.
Não há criação automática de schema no startup nem seeds de contas ou movimentações.

Os testes usam apenas PostgreSQL 18 descartável, sem rede externa, portas publicadas
ou socket/credenciais do cluster de produção. `TEST_DATABASE_URL` mantém a guarda do
banco de teste. A suíte cobre saldo inicial, centavos/valores grandes, receitas,
despesas, transferências/atomicidade, edição/exclusão, arquivamento, categorias,
filtros/paginação, fuso, futuro, isolamento e concorrência, além da autenticação.

`tests/check_finance_migration.py` exige o banco descartável
`salvadindin_migration_test` e APP_ENV=test; valida upgrade com usuário/sessão já
existentes e confirma seed de categorias sem entradas financeiras. Não use em produção.

O fluxo de navegador `frontend/tests/finance.e2e.cjs` aceita somente HTTPS em
loopback e testa os três módulos com dados sintéticos descartáveis, confirmação de
exclusão, centavos grandes, saldo negativo, temas, responsividade e logout.
`auth.e2e.cjs` continua cobrindo acesso e recuperação, agora em `/app/security`.

Aplicação: validar build/testes/Compose, aplicar upgrade somente em salvadindin_v2,
atualizar somente backend, concluir seed idempotente e atualizar frontend. Não é
necessário alterar Nginx, PostgreSQL, portas ou outros projetos. Em regressão de
código, preserve as tabelas novas e os dados; não use downgrade destrutivo como teste
ou reversão em produção. Não execute down -v, prune, reboot ou exclusões globais.

## Resultado da validação

- 69 testes aprovados, sem skips, no PostgreSQL isolado; inclui os testes de acesso
  existentes e os testes financeiros, com concorrência entre arquivamento/escrita
  e exclusão de categoria/escrita, além de transferências concorrentes.
- Upgrade de usuário/sessão preexistentes comprovado em outro banco descartável;
  nenhuma conta ou movimentação é semeada. Alembic check sem divergências.
- Fluxos completos de finanças e autenticação aprovados no navegador HTTPS local,
  incluindo edição, confirmação de exclusão, categorias, arquivamento/reativação,
  saldo negativo e centavos em valores grandes. O fluxo financeiro foi confirmado
  também com a imagem final do backend publicada.
- Builds de frontend e imagens, lint, formatação e dependências validados.
- Produção em `0002_finance (head)`, backend/frontend saudáveis, readiness com banco
  OK, domínio e www em HTTPS. Todas as rotas financeiras recusam chamadas anônimas;
  as páginas privadas redirecionam para login sem mostrar conteúdo privado.
- Nenhuma conta sintética ou movimentação de teste foi criada na produção. Seeds
  de produção inserem apenas as categorias iniciais solicitadas. Dados de testes
  permanecem exclusivamente em instâncias descartáveis, removidas após validação.
- Serviços, configurações e domínios protegidos permaneceram intactos. Não foi
  necessário alterar Nginx, PostgreSQL ou o ambiente privado de produção.

Não foram executados testes de carga de grande volume; a serialização é por usuário
para privilegiar consistência nesta fase. Parcelamentos, recorrências, cartões,
exportação e orçamentos não fazem parte desta missão.
