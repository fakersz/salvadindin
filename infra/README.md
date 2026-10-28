# Infraestrutura de desenvolvimento

O Compose usa o projeto `salvadindin-v2`, as redes `salvadindin_v2_application`
e `salvadindin_v2_database` e o volume `salvadindin_v2_postgres_data`.
Não há mapeamento de porta do PostgreSQL. Backend e frontend escutam somente
em `127.0.0.1:18000` e `127.0.0.1:15173` no host.

Antes de iniciar, execute `python3 infra/preflight.py`, confira `docker compose ls`,
`docker ps`, `ss -lnt`, `free -h` e `df -h`. Não inicie se houver conflitos.
Os limites de memória somam 1 GiB; builds precisam de memória adicional.
Execute Docker somente em ambiente já preparado para isso.

A VPS atual não tem Docker Engine. A configuração foi validada com Compose
standalone, sem daemon; nenhum container foi iniciado. Instalar o daemon pode
alterar firewall/rede do host e requer uma etapa específica de infraestrutura.
Não altere Nginx, PostgreSQL ou serviços existentes para executar esta fundação.

Frontend serve o build com Nginx **dentro do container**, como usuário sem privilégios.
Isso não configura o Nginx instalado no host. Backend usa UID 10001; a imagem
oficial do PostgreSQL inicializa permissões e executa o banco como `postgres`.
A rede do banco é interna, e os serviços não têm política de reinício automático.
As aplicações têm `no-new-privileges` e removem capabilities.

Para interromper apenas este projeto, use `docker compose stop`. Não remova volumes.
Não aponte Alembic nem o backend para o PostgreSQL de produção.
