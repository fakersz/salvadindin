# Salvadindin

Sistema simples de controle financeiro pessoal feito em PHP e PostgreSQL.

Acesse e teste: https://salvadindin.online/

## Recursos

- Cadastro e login de usuarios
- Dashboard financeiro
- Lancamentos de receitas e despesas
- Metas financeiras
- Investimentos
- Painel administrativo

## Requisitos

- PHP 8.2+
- PostgreSQL 14+
- Servidor web apontando para a pasta `public`

## Configuracao

1. Copie o arquivo de ambiente:

```bash
cp .env.example .env
```

2. Ajuste as variaveis do banco e da URL no `.env`.

3. Crie o banco PostgreSQL e importe a estrutura:

```bash
psql -U salvadindin_user -d salvadindin -f database/schema.sql
```

4. Configure o servidor web para usar `public` como raiz do site.

## Prints

<p>
  <img src="imgprints/print.jpeg" alt="Print do Salvadindin" width="420">
  <img src="imgprints/print2.jpeg" alt="Print do dashboard do Salvadindin" width="420">
  <img src="imgprints/print3.jpeg" alt="Print de tela financeira do Salvadindin" width="420">
  <img src="imgprints/print4.jpeg" alt="Print de tela do Salvadindin" width="420">
  <img src="imgprints/print44.jpeg" alt="Print de painel do Salvadindin" width="420">
  <img src="imgprints/a.jpeg" alt="Print da aplicacao Salvadindin" width="420">
  <img src="imgprints/aa.jpeg" alt="Print da interface do Salvadindin" width="420">
  <img src="imgprints/as.jpeg" alt="Print de recursos do Salvadindin" width="420">
  <img src="imgprints/assd.jpeg" alt="Print de demonstracao do Salvadindin" width="420">
  <img src="imgprints/sada.jpeg" alt="Print do sistema Salvadindin" width="420">
  <img src="imgprints/safc.jpeg" alt="Print final do Salvadindin" width="420">
</p>

## Seguranca

O arquivo `.env` real nao deve ser publicado. Use apenas `.env.example` como referencia.
