# WhatsApp oficial — Missão 06

A integração usa somente a WhatsApp Business Platform Cloud API da Meta. Nenhum
cliente de WhatsApp Web, IA externa, pagamento bancário, transferência bancária,
mensagem promocional ou notificação proativa é usado.

## Estado e configuração

Sem as cinco variáveis abaixo, o serviço fica indisponível: a tela informa isso,
a geração de códigos e os dois webhooks retornam 503. Nenhuma credencial é
inventada ou preenchida pelo código. Configuração completa permite a integração,
mas não comprova conexão, aprovação, faturamento ou entrega pela Meta.

Configurar exclusivamente no arquivo privado `/etc/salvadindin/backend.env`,
preservando as demais variáveis e as permissões existentes (diretório 700,
arquivo 600). Não colocar valores em comandos, issues, screenshots ou Git:

| Variável | Origem |
| --- | --- |
| `META_APP_SECRET` | App Secret do aplicativo Meta |
| `WHATSAPP_VERIFY_TOKEN` | Token de verificação aleatório definido pelo operador e informado também à Meta |
| `WHATSAPP_ACCESS_TOKEN` | Token de produção com `whatsapp_business_messaging`, acesso à WABA/número e validade administrada |
| `WHATSAPP_PHONE_NUMBER_ID` | ID do número empresarial registrado, não o telefone de um usuário |
| `WHATSAPP_API_VERSION` | Versão Graph API vigente suportada pelo aplicativo, no formato `vNN.N` |

Não há versão Graph presumida em produção. A versão `v26.0` nos testes é apenas
um parâmetro sintético do contrato HTTP. Confirmar a versão real no painel Meta.
`AUTH_SECRET` existente também precisa ter pelo menos 32 caracteres.

Na Meta: configurar o número/WABA oficial, permissões e modo de produção,
políticas/consentimento e pagamento aplicáveis; cadastrar o callback HTTPS
`https://salvadindin.online/api/integrations/whatsapp/webhook`, o mesmo Verify
Token, e assinar o campo `messages`. Divulgar ao usuário o contato oficial do bot;
o sistema não apresenta o Phone Number ID como se fosse um telefone.

Depois da configuração real, recriar **somente o backend** do SalvaDinDin e
verificar saúde. Realizar uma vinculação e um lançamento consentidos de ponta a
ponta usando a Meta, incluindo recebimento da resposta. Só então declarar o canal
operacional. Não criar contas ou lançamentos fictícios em produção para testar.

Fontes oficiais consultadas:
- [Coleções oficiais da Meta](https://www.postman.com/meta/whatsapp-business-platform/overview)
- [Envio de texto](https://www.postman.com/meta/whatsapp-business-platform/request/8gvd47s/send-text-message)
- [Contrato de assinatura e verificação](https://whatsapp.github.io/WhatsApp-Nodejs-SDK/api-reference/webhooks/start/) (referência arquivada; o SDK não é uma dependência)
- [Webhooks atuais](https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/create-webhook-endpoint)
- [Política e preços atuais](https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing)

A documentação Meta atual de webhooks/preços exigiu login ou retornou 429 durante
a consulta. Não foram presumidos valores, franquias gratuitas ou preços antigos.
Conferir as condições vigentes e a versão no painel oficial antes da ativação.
O código restringe respostas de texto à janela de 24 horas da mensagem recebida,
nunca envia templates nem inicia conversas. Prompts de confirmação também expiram
em 10 minutos. Fora da janela, a resposta é descartada; não há fallback proativo.

## Endpoints

| Método e caminho | Proteção e função |
| --- | --- |
| `GET /api/integrations/whatsapp/webhook` | Modo subscribe, Verify Token constante e retorno do challenge |
| `POST /api/integrations/whatsapp/webhook` | HMAC-SHA256 do corpo bruto com App Secret, antes do JSON |
| `GET /api/integrations/whatsapp/status` | Sessão autenticada; disponibilidade, vínculo e expiração do código |
| `POST /api/integrations/whatsapp/link-codes` | Sessão + CSRF; gera/substitui código |
| `DELETE /api/integrations/whatsapp/link-codes` | Sessão + CSRF + JSON `{"confirm":true}`; revoga códigos pendentes |
| `DELETE /api/integrations/whatsapp/link` | Mesma proteção; remove vínculo e cancela pendências |

Corpo do webhook limitado a 256 KiB; apenas eventos `messages` do Phone Number ID
configurado são processados. Atualizações de status não geram lançamentos nem
respostas. Mensagens futuras além da tolerância de dois minutos ou com mais de
24 horas são ignoradas. Eventos inválidos não têm seu conteúdo refletido ou logado.
Falha transacional retorna erro para permitir nova entrega pela Meta.

## Fluxo do usuário

Abrir `/app/security` → Integrações → `/app/settings/integrations`.
Gerar código, exibido uma vez, com 80 bits aleatórios e validade de 10 minutos.
Enviar `VINCULAR XXXX-XXXX-XXXX-XXXX` ao bot oficial. Só o webhook autenticado
consome o código e cria o vínculo; gerar código não conecta o WhatsApp.

Cada conta admite um WhatsApp e cada identificador admite uma conta. Unicidade no
banco e locks impedem consumo concorrente. Um número já vinculado nunca é movido
para outra conta automaticamente, inclusive se o usuário anterior estiver inativo.
É preciso desvincular explicitamente. Limites persistidos: cinco gerações por
usuário/15 minutos, cinco tentativas de vinculação por remetente/15 minutos e
30 mensagens por remetente/15 minutos. Não existe pesquisa pública de vínculos.

Mensagens suportadas:

- `gastei 35 no mercado`
- `paguei 80 de gasolina`
- `recebi 2100 de salário`
- `entrada 150 pix`
- `despesa 45,90 almoço`

BRL usa vírgula decimal e pontos de milhar: `1.234,56`. Valores zero, negativos,
excesso de casas, moedas estrangeiras, dois valores e instruções ambíguas são
recusados. Decimal e o schema financeiro validam o valor, sem conversão para float.

Uma única conta ativa pode ser sugerida. Havendo várias, o bot pergunta e lista
somente contas do usuário. Responder `CONTA 1 ABC234`, usando a opção e o código
recebidos. É possível informar `na conta NOME EXATO` ao final do lançamento;
nomes duplicados/ambíguos não são resolvidos automaticamente.

Categorias são sugeridas apenas por palavras definidas e pela existência da
categoria correspondente do próprio usuário/tipo: mercado/almoço → Alimentação,
gasolina → Transporte, salário → Salário, por exemplo. Sem correspondência única,
a sugestão é `Sem categoria`; nenhuma categoria ou conta é criada pelo bot.

**Todo lançamento exige confirmação**, mesmo quando a interpretação é clara.
O resumo informa tipo, valor, descrição, conta e categoria. Responder
`SIM ABC234` ou `NÃO ABC234`, com o código específico da ação. SIM/NÃO sem o
código não registra. Cada pendência dura 10 minutos; uma mensagem financeira nova
substitui a anterior. Conta arquivada, categoria incompatível, vínculo revogado ou
pendência expirada impedem a gravação. Data/hora vêm da mensagem oficial.

## Persistência, isolamento e envio

A migration `0003_whatsapp` adiciona apenas:

- `whatsapp_links`: identificador criptografado e índice HMAC para busca/unicidade;
- `whatsapp_link_codes`: hash do código e datas de uso, revogação e expiração;
- `whatsapp_inbound_messages`: hashes de IDs/remetentes, resultado e fila de resposta;
- `whatsapp_pending_actions`: rascunho criptografado e estado de confirmação.

Não altera registros financeiros anteriores e não cria seeds de vínculos ou
lançamentos. Pendências têm FK composta para assegurar vínculo/usuário no banco.
A criação de movimentações foi extraída para `finance/service.py`: API web e bot
usam exatamente a mesma validação e inserção. O lock do usuário serializa alterações
financeiras, arquivamento, confirmações e desvinculação.

A mensagem deduplicada, a confirmação consumida e o lançamento são confirmados
na mesma transação. O ID da Meta é persistido como HMAC único; duas entregas do
mesmo ID e confirmações repetidas não geram novos lançamentos.

Um worker no ciclo de vida do backend consulta a fila durável a cada cinco
segundos, até cinco respostas por rodada, sem criar outro serviço/container.
Testes não iniciam o worker e usam mocks da API oficial. Chamadas usam HTTPS/TLS,
host fixo `graph.facebook.com`, Bearer no cabeçalho, timeout e nenhum redirecionamento.

429 explícito permite até três tentativas com espera limitada. Timeout, 5xx ou
resposta ambígua ficam sem reenvio automático, pois a Meta pode ter aceitado a
mensagem: isso evita duplicar respostas. Queda após o claim também não provoca
reenvio cego. `sent` significa aceito pela API, não entregue/lido. Um erro de envio
nunca desfaz ou repete uma movimentação. O usuário pode conferir o site se não
receber a resposta. Uma requisição externa já em andamento não pode ser recolhida
quando o usuário desvincula; pendências e respostas ainda na fila são canceladas.

## Privacidade e retenção

Identificadores, rascunhos e texto das respostas na fila usam Fernet autenticado.
Busca e deduplicação usam HMAC-SHA256 com domínios separados. A chave de criptografia
é uma subchave de 256 bits derivada de `AUTH_SECRET` com outro domínio. Nenhum
telefone, hash ou ciphertext do usuário é exposto na interface/status.

Não persistimos o corpo completo do webhook nem o histórico integral da conversa.
Rascunhos são apagados ao consumir/cancelar/expirar. Texto e destinatário de
respostas são apagados ao concluir ou expirar. O worker remove códigos/pendências
expirados há mais de um dia e metadados de mensagens após 30 dias. Mensagens
anteriores à janela são recusadas mesmo após a limpeza da deduplicação.

Proteger o banco e `AUTH_SECRET` separadamente. Antes de rotacionar esse segredo,
desvincular canais e revogar pendências; novos vínculos serão necessários. Não
substituir o segredo sem planejamento: ciphertext antigo se torna ilegível.

## Validação repetível

Usar `backend/Dockerfile.test`, PostgreSQL 18 descartável com `--network none`,
sem portas publicadas, sem socket/credenciais do PostgreSQL do host. Compartilhar
somente o namespace de rede do PostgreSQL temporário com o runner. `APP_ENV=test`.
`TEST_DATABASE_URL` deve apontar exatamente para `salvadindin_auth_test`.

Executar `alembic upgrade head`, `alembic check`, `pytest -q -p no:cacheprovider`.
`tests/check_whatsapp_migration.py` exige outro banco descartável chamado
`salvadindin_migration_test`; verifica upgrade, reversão e upgrade mantendo
usuário, sessão, conta e movimentação sintéticos já existentes.

O navegador usa `infra/auth-test-proxy.py`, HTTPS loopback e backend descartável
sem rede externa. `frontend/tests/whatsapp.e2e.cjs` exige destino loopback e um
App Secret sintético em `WHATSAPP_E2E_APP_SECRET`. Testa geração, revogação,
vinculação por evento assinado, polling, desvinculação e estado indisponível.
As suítes existentes de autenticação e finanças também devem continuar passando.
Nenhum número real, dado financeiro real ou chamada real à Meta é usado nesses testes.

## Resultado da implantação — 29/10/2026

- 105 testes backend aprovados no PostgreSQL 18 descartável, sem skips; Ruff aprovado.
- Migração com dados sintéticos anteriores, reversão e novo upgrade aprovados; `alembic check` sem divergência.
- Três suítes HTTPS de navegador aprovadas: WhatsApp, autenticação e núcleo financeiro.
- TypeScript/Vite e builds Docker do backend/frontend aprovados.
- `0003_whatsapp` aplicada somente em `salvadindin_v2`, com as quatro tabelas novas vazias.
- Backend e frontend de produção saudáveis. Webhook público retorna 503 por ausência das credenciais; rotas de vínculo exigem autenticação.
- O ambiente privado foi preservado. Anninha15, Nginx do host e PostgreSQL permaneceram com os mesmos processos e configurações; seus dois domínios responderam HTTP 200.
- Nenhum número real, vínculo real, chamada real à Meta ou lançamento fictício foi usado em produção.
- A integração **não está conectada à Meta**. Falta configurar as cinco variáveis, concluir a configuração oficial e comprovar o fluxo real.

O gateway HTTPS descartável emitiu avisos `BrokenPipeError` quando o navegador
cancelou requisições durante navegação; as três suítes concluíram com sucesso.
Isso não ocorreu no backend ou no Nginx de produção.


## Ativação e logs protegidos — 29/10/2026

As cinco variáveis oficiais foram fornecidas pelo operador no arquivo privado.
O backend foi recriado sozinho; a correspondência do ambiente carregado com o
arquivo root:root 600 foi conferida sem exibir valores. Isso não comprova a
validade do Access Token ou permissões da Meta.

`compose.yaml` usa `/app/logging.json`: logs de acesso da aplicação preservam
cliente, método, rota e status, omitindo integralmente a query. Logs de erro
redigem os valores de segredos conhecidos e cabeçalhos sensíveis; exceções
registram somente o tipo, sem traceback/payload potencialmente secreto.

No Nginx, instalar também `infra/nginx/salvadindin-logging.conf` em
`/etc/nginx/conf.d/salvadindin-logging.conf`, antes de usar o vhost deste repositório.
O formato é aplicado somente aos vhosts SalvaDinDin, preserva método, URI sem
query, status, tamanho, duração e status/duração do upstream. Não registra
Cookie, Authorization, Referer, User-Agent, corpo ou argumentos. Erros brutos do
Nginx desses dois vhosts vão a `/dev/null`, pois podem conter a URL completa;
o diagnóstico HTTP permanece nos logs de acesso estruturados e nos logs
sanitizados da aplicação. Configurações e logs dos outros projetos não mudam.

Verificação objetiva HTTPS: GET correto 200 com challenge exato; GET incorreto
403; POST sem assinatura, inválido e adulterado 403; envelope `entry: []`
corretamente assinado 200. Marcadores de query, Cookie e Authorization e os
segredos reais estiveram ausentes dos logs inspecionados. Nove testes locais
focados em logs e saúde passaram. Nenhuma mensagem real, vínculo ou movimentação
foi criado nos testes. Frontend e arquivo privado preservados. Ainda falta
comprovar configuração/callback e fluxo real autorizado no painel da Meta.
