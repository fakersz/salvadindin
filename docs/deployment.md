# Validação da publicação — 2026-10-28

Fundação publicada em https://salvadindin.online e https://www.salvadindin.online.
Branch de publicação: `v2-development`. Sem merge em `main` ou `legacy-v1`.

## Infraestrutura aplicada

- Ubuntu 26.04.1 LTS, 1 vCPU, 3,8 GiB de RAM, sem swap.
- Docker Engine 29.9.2, Compose Plugin 5.6.1, Buildx 0.38.1, repositório oficial.
- Docker/containerd habilitados no boot; socket do daemon sem exposição TCP;
  nenhum usuário adicionado ao grupo docker.
- Dois containers sem root, saudáveis: `salvadindin-backend` e
  `salvadindin-frontend`, rootfs somente leitura, tmpfs, limites de CPU/memória,
  processos e logs com rotação. Imagens-base fixadas pelos digests utilizados.
- Portas publicadas apenas em `127.0.0.1:18000` e `127.0.0.1:15173`.
- PostgreSQL 18.6 existente, sem segunda instância ou container de banco.
- Banco `salvadindin_v2` e role `salvadindin_app`, sem privilégios administrativos,
  senha exclusiva em arquivo root-only `/etc/salvadindin/backend.env`, fora do Git.
- Backend autentica via socket Unix com SCRAM. HBA permite essa role apenas no
  banco do projeto por socket e rejeita seu acesso aos demais bancos e por TCP.
- Configuração de escuta PostgreSQL permanece localhost; sem reinício do cluster.
- Nginx recebe apenas um vhost novo para os dois domínios, com caminhos de API
  preservados, headers de proxy substituídos e headers de segurança.
- Certificado Let's Encrypt válido para ambos os nomes, expira em 2027-01-26;
  HTTP redireciona para HTTPS. Sem HSTS preload.

## Resultados reais

- `docker compose config --quiet`: aprovado, sem imprimir credenciais.
- Build Docker backend/frontend: aprovado, incluindo instalação com hashes Python,
  `npm ci`, TypeScript e bundle Vite; build confirmado com digests fixados.
- pytest: **11 aprovados**, incluindo compatibilidade do socket com Psycopg.
- Ruff lint e formatação: aprovados; `pip check`: sem dependências inconsistentes.
- Auditoria npm: sem vulnerabilidades reportadas na execução registrada.
- `alembic upgrade head` executado a partir da imagem backend com a role do
  projeto: sucesso, exclusivamente em `salvadindin_v2`. Não existem revisões de
  negócio; tabela `alembic_version` criada e vazia, `alembic current` sem revisão.
- Conexão real por socket: aprovada. Tentativas dessa role em outro banco e via
  TCP: rejeitadas, sem consultar registros de outros projetos.
- `/`, assets JavaScript/CSS, `/api/health` e `/api/health/ready`: HTTP 200 nos dois
  domínios via HTTPS, validação TLS ativa. Readiness confirma banco acessível.
- `/api/docs` e `/api/openapi.json`: HTTP 404 em produção, conforme esperado.
- Redirecionamentos HTTP: 301 para HTTPS nos dois nomes.
- `nginx -t`: aprovado antes dos reloads.
- Renovação: `certbot renew --dry-run --cert-name salvadindin.online` aprovada;
  timer ativo/habilitado e hook exclusivo executado com validação e reload aprovados.
- Restart `unless-stopped` comprovado encerrando o processo principal de cada
  container isoladamente: ambos reiniciaram automaticamente e voltaram a healthy,
  com incremento de RestartCount. Nenhum reboot ou restart do host foi executado.
- Listeners conferidos: 5432 somente localhost IPv4/IPv6; 15173 e 18000 somente
  loopback IPv4; sem listeners TCP 2375/2376. Conexões ao IPv4 público nessas
  portas, originadas da própria VPS, foram recusadas/indisponíveis.
- Serviços e domínios preexistentes continuaram ativos e com HTTPS 200/TLS válido;
  PIDs principais preservados, sem restart. Hashes dos vhosts protegidos, unidade
  protegida e `postgresql.conf` permaneceram idênticos. ACL do banco e atributos
  da role protegidos permaneceram iguais. Nenhuma consulta a registros pessoais.

As verificações HTTP acima foram feitas a partir da VPS usando URLs públicas
com validação de certificado. A ferramenta externa de navegação não conseguiu
buscar essas URLs e não foi considerada evidência de disponibilidade externa.
Não foi feito teste visual em navegador ou teste de carga.

## Limites e pendências

A publicação foi explicitamente autorizada sem novos backups ou teste de
restauração. Nenhuma recuperação comprovada é declarada. Backups recorrentes,
monitoramento e restauração isolada continuam pendentes, assim como CI,
funcionalidades de produto, licença e revisão de segurança/privacidade.
Os limites de runtime não limitam automaticamente o consumo dos builds.
Health check malsucedido sozinho não provoca reinício automático.
