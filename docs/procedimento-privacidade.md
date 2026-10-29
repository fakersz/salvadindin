# Atendimento administrativo de privacidade — SalvaDinDin

Procedimento manual para Jean Peixoto, responsável informado pelo mantenedor.
Não há endpoint de exclusão integral nem exportação completa por botão. Este
procedimento não executa alterações e não autoriza apagar dados sem um pedido
verificado do titular. O canal publicado nas páginas deve ser real e acompanhado.

## Recebimento e verificação

1. Receber o pedido pelo canal privado publicado. Criar referência aleatória do
   atendimento, data, escopo e meio para resposta em registro privado com acesso
   restrito, fora do Git e dos logs HTTP. Não copiar lançamentos para esse registro.
2. Normalizar o nome de usuário conforme a aplicação. Consultar somente o registro
   exato no banco `salvadindin_v2`, por parâmetro SQL; obter seu UUID internamente.
   Conhecer o nome de usuário ou possuir um e-mail não prova a titularidade.
3. Gerar desafio aleatório de uso único, por exemplo 128 bits, com validade de
   15 minutos e associação privada ao UUID/pedido. Não solicitar senha, cookies,
   códigos de recuperação ou capturas de dados financeiros.
4. Uma forma de prova usando recursos já existentes: pedir que o titular, após
   autenticar-se normalmente no site, crie uma categoria temporária de nome
   `PRIVACIDADE-<desafio>` (cabe no limite de 60 caracteres), sem lançamentos.
   O operador verifica **somente** a existência dessa categoria sob o UUID
   esperado, o nome exato e a data dentro da validade. A autorização da API impede
   criá-la na conta de outra pessoa. Marcar o desafio como consumido; o titular
   pode remover a categoria depois, e a exclusão integral também a removerá.
5. Alternativamente, um WhatsApp já vinculado pode ajudar na verificação por um
   desafio privado, conferido pelo operador sem divulgar o identificador ou
   histórico. Não considerar a simples apresentação de um número como prova.
   Se não houver acesso verificável, orientar a recuperação já existente ou
   analisar a situação individualmente. Não apagar dados só com uma alegação.
6. Confirmar o escopo e a intenção: só WhatsApp, dados específicos ou toda a conta.
   Para acesso/correção, fornecer ou alterar somente os registros do UUID
   verificado. Fornecer a resposta privadamente; não publicar dados em issues.
   Observar prazos legais, sem inventar um prazo único para todos os pedidos.

## Exclusão integral após prova e análise

A implementação deve ser executada por operador autorizado em sessão privada,
com consulta parametrizada e revisão de escopo. Não fazer isso pelo chat ou
colar dumps. Evitar comandos ad hoc com identificadores em histórico público.
Não há necessidade de acesso a bancos de outros projetos.

- Verificar `current_database() = 'salvadindin_v2'`, conexão por socket e destino
  correto. Não usar Anninha15 nem outros bancos, e não executar migrações.
- Preparar uma prévia com **contagens** por `user_id = :uuid`, sem valores ou
  descrições. Conferir que o UUID veio da etapa verificada. Nunca usar um filtro
  por nome parcial, telefone digitado livremente ou sem cláusula de proprietário.
- Antes de apagar, avaliar conservação exigida por obrigação concreta ou exercício
  de direitos. Se houver retenção excepcional, registrar categoria de dados,
  fundamento, acesso restrito e critério de duração. Não conservar todo o histórico
  por uma justificativa genérica. Comunicar a limitação ao titular.
- Em uma única transação, bloquear a linha do usuário (`SELECT ... FOR UPDATE`),
  conferir novamente o UUID e desativar o usuário. Esse bloqueio é compartilhado
  com os escritores financeiros e o webhook. Cancelar respostas ainda na fila
  para o vínculo atual, apagando texto e destinatário criptografados, e revogar
  códigos/sessões. Requisições Meta já em andamento podem não ser recolhidas;
  não prometer cancelamento retroativo de uma chamada externa.
- Remover, **sempre por UUID**, pendências WhatsApp, movimentações, categorias,
  contas financeiras, códigos de vínculo, vínculo WhatsApp, sessões e códigos de
  recuperação; remover o usuário ao final. Essa ordem respeita as referências
  financeiras e evita depender de ordem implícita de cascatas. O banco também
  possui FKs de proprietário. Não selecionar objetos de outros usuários.
- Metadados de mensagens recebidas não têm `user_id`: são indexados por HMAC do
  remetente para deduplicação e segurança. Não adivinhar associações após uma
  desvinculação nem apagar histórico de outro titular que possa ter usado o mesmo
  identificador. O conteúdo em fila do vínculo atual deve ser cancelado; metadados
  mínimos residuais seguem a limpeza de 30 dias e a análise legal documentada.
- Conferir, antes do commit, zero linhas do UUID nas tabelas aplicáveis e zero
  respostas pendentes para o vínculo removido. Se houver dúvida, erro de FK ou
  destino divergente, executar rollback e investigar; nunca ampliar o filtro.
- Após commit, conferir novamente pelo UUID em consulta separada. Registrar apenas
  referência do atendimento, operação, resultado, data e eventual retenção; não
  guardar cópia desnecessária dos registros excluídos. A confirmação ao titular
  deve explicar alcance e limitações, inclusive a base ativa versus cópias.

Para exclusão parcial, usar as mesmas garantias, sem remover o usuário e mantendo
as referências válidas. Desvincular WhatsApp pela interface já existente é uma
operação diferente: não apaga lançamentos financeiros ou mensagens na Meta.

## Cópias, logs e restauração

Inventariar cópias de segurança **do SalvaDinDin**, quando existentes, antes de
afirmar exclusão completa. Backups de outros projetos não entram no escopo.
Restringir uso residual, registrar o descarte e exigir reaplicação das exclusões
antes de reabrir uma restauração. Não há ciclo de backup/descarte universal
comprovado nesta tarefa; não anunciar eliminação instantânea de todas as cópias.
Logs têm finalidade de operação/segurança e rotação, não justificam guardar dados
financeiros ou credenciais. Analisar pedidos incidentes sobre eles de forma
proporcional, sem expor outros titulares ou modificar registros de outros projetos.

## Referências e limites

- LGPD: https://www2.camara.leg.br/legin/fed/lei/2018/lei-13709-14-agosto-2018-787077-normaatualizada-pl.html
- Orientação da ANPD: https://www.gov.br/anpd/pt-br/assuntos/titular-de-dados/direito-dos-titulares

As páginas não declaram certificação de conformidade integral, aprovação Meta,
criptografia de ponta a ponta no PostgreSQL ou licença de código inexistente.
A licença específica está em definição no README; não foi criada nesta missão.
O fluxo de melhorias do bot foi preservado separadamente e não integra esta publicação.

## Estado desta entrega

Responsável informado e autorizado para publicação: Jean Peixoto.
Canal privado de contato informado pelo responsável: devjeanpeixoto@gmail.com.
O domínio Gmail possui servidores MX válidos. A verificação de DNS não prova,
isoladamente, a existência ou o recebimento desta caixa específica. Nenhuma
mensagem de teste foi enviada automaticamente. Acompanhar a caixa e confirmar
um recebimento real antes de declarar o atendimento operacional de ponta a ponta.

As páginas possuem HTML pré-renderizado, não dependem de login/API e têm rodapé
com os três links. Atualizar somente o frontend e conferir as três URLs por HTTPS.
A licença específica do código continua em definição. O procedimento administrativo
acima está documentado; não foi executada exclusão de dados de produção.

Publicação verificada em 29/10/2026: `/privacidade`, `/termos` e
`/exclusao-de-dados` retornaram HTTP 200 direto por HTTPS, em duas requisições
independentes, com texto, identidade autorizada, mailto correto e rodapé presentes
no HTML sem autenticação. Somente o frontend foi recriado e ficou saudável;
backend, ambiente privado e configurações Anninha15 foram preservados.
