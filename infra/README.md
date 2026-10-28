# Infraestrutura SalvaDinDin

## Escopo

A implantação usa o PostgreSQL 18 e o Nginx existentes no host, sem outra
instância de banco. Templates deste diretório são exclusivos do SalvaDinDin;
não substitua configurações globais existentes sem revisar seu conteúdo.

- `nginx/salvadindin.conf`: vhost dos dois domínios e proxies que preservam `/api/`.
- `pg_hba_salvadindin.conf`: autenticação por socket restrita à role do projeto.
- `docker-daemon.json`: configuração inicial do daemon, logs e live restore.
- `salvadindin-cert-renew`: hook de renovação restrito ao certificado do projeto.
- `preflight.py`: consulta recursos e testa disponibilidade das portas antes do
  primeiro início. Com containers já ativos, portas ocupadas são esperadas.

## Pré-requisitos e privilégios

Docker Engine, Compose Plugin e Buildx foram instalados pelo repositório oficial
compatível com Ubuntu 26.04. O daemon e containerd iniciam no boot. Não há membros
adicionados ao grupo Docker nem listener TCP do daemon.

PostgreSQL continua com `listen_addresses=localhost`, sem mudança em
`postgresql.conf`. `pg_hba.conf` inclui `pg_hba_salvadindin.conf` antes das regras
gerais. Foi feito reload de configuração, sem reiniciar o cluster. As novas
regras só correspondem à role `salvadindin_app`: SCRAM no banco `salvadindin_v2`,
rejeição nos demais bancos e por TCP. As regras anteriores permanecem intactas.

O banco pertence ao administrador `postgres`; a role da aplicação tem CONNECT
somente conforme ACL e USAGE/CREATE no schema public para executar migrações,
limite de 10 conexões e sem privilégios administrativos. PUBLIC não tem acesso
ao banco/schema do projeto. O pool da aplicação usa 3 conexões e overflow de 2.
O tempo de consulta é limitado a 15s e o de espera por lock a 5s para essa role
nesse banco. A revisão de privilégios da role da aplicação será necessária
quando migrações e funcionalidades crescerem.

O arquivo `/etc/salvadindin/backend.env` contém a senha exclusiva e não é
versionado. Diretório root 0700, arquivo root 0600. O diretório de socket
`/var/run/postgresql` é montado em `/run/postgresql` somente no backend, como
somente leitura; não é exposto ao frontend. As permissões existentes do socket
do cluster não foram alteradas. O backend usa UID 10001 e autenticação com senha,
sem receber a identidade ou grupo do usuário postgres do host.

## Rede e processos

Rede `salvadindin_v2_application`, subnet `172.30.10.0/24`, sem host networking.
Somente `127.0.0.1:18000` e `127.0.0.1:15173` são publicados. O backend confia em
headers de proxy apenas do gateway `172.30.10.1`; o Nginx do host substitui os
headers encaminhados a partir do cliente, preservando caminhos e protocolo.

Backend: 384 MiB, 0,50 CPU, 100 processos. Frontend: 128 MiB, 0,25 CPU,
50 processos. Rootfs somente leitura e tmpfs /tmp de 32 MiB por container.
Logs JSON têm rotação de 10 MiB, 3 arquivos. Restart policy `unless-stopped`:
parada administrativa deliberada é respeitada. Saúde degradada não reinicia
containers automaticamente. Os builds requerem memória adicional e devem ser
planejados para não competir com os serviços existentes.

## HTTPS

Vhost `/etc/nginx/sites-available/salvadindin`, habilitado por symlink.
Desafio ACME por webroot `/var/lib/salvadindin/acme`; certificado em
`/etc/letsencrypt/live/salvadindin.online`. Timer `certbot.timer` e hook
`/etc/letsencrypt/renewal-hooks/deploy/salvadindin-reload` verificam e recarregam
Nginx após renovação desse certificado. Vhosts/certificados de outros projetos
não são editados. Sempre execute `nginx -t` antes do reload.

## Operação e reversão

Execute Compose como administrador, nunca publique sua configuração expandida
com segredos. Verifique `docker compose ps` e os endpoints antes/depois de cada
atualização. Migrações são explícitas e só podem usar `salvadindin_v2`.

Para interromper apenas a aplicação: `sudo docker compose stop`. Não remova
volumes ou bancos. Em regressão de publicação, desabilite apenas o symlink
SalvaDinDin do Nginx, teste a configuração e faça reload, preservando todos os
outros vhosts. As regras HBA exclusivas podem ser desabilitadas removendo apenas
a inclusão do projeto e fazendo reload validado; não reinicie o cluster.
Não remova Docker ou suas regras de rede durante uma reversão sem verificar
se outros workloads passaram a depender dele. Não use prune ou down -v.

Backups recorrentes e restauração comprovada ainda precisam de tratamento
operacional próprio. A publicação não constitui comprovação de recuperação.
