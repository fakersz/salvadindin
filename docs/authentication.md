# Cadastro, login e sessões — Missão 04

## Contrato da API

Todos os endpoints ficam em `/api/auth`. Respostas não são armazenáveis em cache.

| Método | Caminho | Comportamento |
| --- | --- | --- |
| GET | `/csrf` | Emite/reutiliza token CSRF; informa `registration_enabled` |
| POST | `/register` | Cria conta, autentica e exibe 10 códigos uma única vez |
| POST | `/login` | Autentica e substitui a sessão atual do navegador |
| GET | `/me` | Exige sessão válida e retorna apenas id, username, created_at |
| POST | `/logout` | Revoga sessão atual e limpa cookies |
| POST | `/recover` | Redefine senha com username e código; revoga todas as sessões |
| POST | `/recovery-codes/regenerate` | Exige sessão e senha; invalida códigos anteriores |

Cadastro: `username`, `password`, `password_confirmation`. Login: `username`,
`password`. Recuperação: os campos do cadastro mais `recovery_code`. Regeneração:
`password`. Logout: objeto JSON vazio. Não envie dados sensíveis em URLs.

## Dados e concorrência

Revisão Alembic `0001_auth`:

- `users`: UUID, username único normalizado em minúsculas, Argon2id, ativo,
  criação/atualização/último login com timezone. Constraints de tamanho, lowercase
  e caracteres ASCII também no PostgreSQL.
- `sessions`: UUID, FK de usuário, hash SHA-256 de token aleatório de 256 bits,
  criação/expiração/revogação. Índices de usuário e expiração.
- `recovery_codes`: UUID, FK de usuário, hash SHA-256 de UUID + código aleatório
  de 128 bits, criação/uso. Nenhum código é recuperável pelo backend após resposta.
- `auth_rate_limits`: buckets atômicos persistentes, sem armazenar username ou IP
  em claro; identificadores HMAC com segredo privado, índice de expiração.

A constraint UNIQUE trata cadastros simultâneos. Login, recuperação e regeneração
usam locks de linha do usuário; recuperação trava e consome o código na mesma
transação que troca a senha e revoga sessões, impedindo uso concorrente do código
ou criação de sessão com senha antiga após reset. A aplicação não toca outros bancos.

## Política de segurança

Usernames aceitam de 3 a 30 letras ASCII, números, ponto, hífen e underscore,
com espaços externos removidos e lowercase consistente. Senhas mantêm espaços e
caracteres especiais: 12 a 128 caracteres Unicode, sem truncamento. Login aceita
senhas menores apenas para responder erro genérico, sem revelar existência da conta.

Argon2id: 32 MiB, 3 iterações, paralelismo 1; máximo de duas operações simultâneas
por processo para manter o consumo de memória previsível. O hash pode ser atualizado
no login se os parâmetros mudarem. Conta inexistente também faz verificação Argon2.

Cookie `__Host-salvadindin_session`: HttpOnly, Secure, SameSite=Lax, Path=/,
sem Domain; duração padrão de 168 horas também aplicada no servidor. A dependência
`require_auth` verifica atividade do usuário, expiração e revogação no banco. Não há
JWT ou tokens em localStorage. HTTPS é necessário para os fluxos de autenticação.
Cookies são específicos de cada hostname; entrar em um alias não autentica o outro.

Toda mutação, inclusive login/logout, exige JSON, Origin na lista permitida e
header `X-CSRF-Token` correspondente ao cookie CSRF. O token é assinado com HMAC,
expira em duas horas e é vinculado à sessão atual (ou ao contexto anônimo). O cookie
CSRF também é HttpOnly; o frontend obtém o valor via JSON do endpoint same-origin.
Não há CORS permissivo. A proteção CSRF não substitui prevenção de XSS.

Janelas fixas de 15 minutos (requisições bem-sucedidas também contam):

| Ação | Por origem | Por conta |
| --- | --- | --- |
| Login | 30 | 8 |
| Cadastro | 5 | — |
| Recuperação | 20 | 5 |
| Regeneração | 10 | 5 |

Respostas limitadas são 429 com Retry-After. Buckets são atualizados por upsert e
persistem mesmo em falhas; expirados são removidos durante novas tentativas. O IP
vem de `request.client`, nunca de headers lidos pela aplicação. Uvicorn confia
somente no gateway Docker conhecido e Nginx substitui X-Forwarded-For pelo IP do
cliente. Evite introduzir outros proxies sem rever essa cadeia de confiança.

## Configuração privada

Em `/etc/salvadindin/backend.env` (root 0600, diretório 0700):

- `AUTH_SECRET`: aleatório, ao menos 32 caracteres; não reutilizar placeholders.
- `PUBLIC_REGISTRATION_ENABLED=false`: mantém cadastro fechado inicialmente.
- `SESSION_HOURS=168`: duração configurável de 1 a 720 horas.
- `AUTH_ALLOWED_ORIGINS`: JSON com origens HTTPS exatas dos dois domínios.

Os valores padrão já restringem as origens aos dois domínios. O segredo é obrigatório
em produção. Nunca imprima o ambiente completo ou a configuração Compose expandida.
Para ativar cadastro após validação, altere a variável privada para `true` e execute
`sudo docker compose up -d --no-deps backend`; não é necessário alterar Nginx ou Git.

## Testes isolados e publicação

`backend/Dockerfile.test` inclui dependências de teste sem adicioná-las à imagem
final. Os testes de auth exigem `TEST_DATABASE_URL` apontando exatamente para
`salvadindin_auth_test`; sem essa configuração eles são explicitamente skipped.
Não confunda os testes rápidos locais com a suíte completa no PostgreSQL.

A validação foi executada com PostgreSQL 18 em container descartável, `--network
none`, sem portas publicadas, com dados em tmpfs e limites de recursos. O runner
compartilha somente a namespace de rede desse container (`--network container:...`),
acessando seu localhost:5432; não recebe o socket nem credenciais da produção.
O ambiente de teste usa credenciais aleatórias em `/run` privado. Execute `alembic
upgrade head`, `alembic check` e pytest nesse runner. Reversão/reaplicação da migração
é permitida apenas nesse banco descartável, nunca como teste na produção.

O fluxo de navegador em `frontend/tests/auth.e2e.cjs` exige Playwright/Chromium e
um endpoint HTTPS de loopback. `infra/auth-test-proxy.py` serve o build e encaminha
API exclusivamente ao socket Unix do backend de teste em `/run/salvadindin-auth-test`.
Ele não usa o Nginx, cluster ou API de produção. O script testa cadastro, exibição e
confirmação dos códigos, cookies, login/logout, regeneração, recuperação, senha
antiga recusada/nova aceita, temas e responsividade. Não imprime credenciais e
não permite destino público. Para executar, configure `PLAYWRIGHT_MODULE`,
`CHROMIUM_PATH` e, se necessário, bibliotecas/fontes privadas do navegador.

Na publicação: build, testes, configuração Compose, migração com a role exclusiva
e atualização somente dos dois containers. O cadastro permanece fechado durante
os testes iniciais. A validação no domínio verifica as telas, estado do cadastro,
CSRF, /me sem sessão e readiness, sem criar ou apagar contas de produção.

Não existe recuperação por administrador, e-mail, telefone ou pergunta secreta.
Quem perder a senha e todos os códigos não tem recuperação nesta versão. Rotinas
periódicas de limpeza de sessões expiradas, monitoramento e revisão de limites
sob carga continuam como manutenção futura. Não há declaração de conformidade LGPD.

## Resultado registrado da Missão 04

- 42 testes aprovados no PostgreSQL descartável, sem skips nessa execução.
- Migração aplicada, revertida e reaplicada somente no banco descartável;
  `alembic check` sem diferenças. Na produção foi aplicado apenas upgrade aditivo
  para `0001_auth`, exclusivamente em `salvadindin_v2`.
- Fluxo completo de navegador validado com a imagem final do backend em ambiente
  isolado, incluindo senha longa com Unicode, confirmação dos códigos, regeneração
  e recuperação. Layouts de celular/desktop e tema escuro verificados.
- Builds TypeScript/Vite e imagens Docker aprovados; lint e formatação aprovados.
- Domínio e www com HTTPS; login, cadastro fechado e recuperação renderizados;
  /app redireciona sem sessão. CSRF e /me rejeitam acesso indevido conforme esperado.
- Cadastro público permanece desativado. Nenhuma conta sintética foi criada no banco
  de produção. Somente containers SalvaDinDin foram atualizados, sem reload Nginx,
  restart PostgreSQL, reboot, alterações em outros bancos ou recursos protegidos.

A validação positiva do cadastro/recuperação usou banco descartável. Em produção
foram executadas verificações de disponibilidade, interface e bloqueios, preservando
o estado inicial fechado. Para liberação pública, falta apenas a decisão operacional
de ativar `PUBLIC_REGISTRATION_ENABLED`, não uma nova implementação de autenticação.
