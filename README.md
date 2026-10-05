# php-migrate

[![CI](https://github.com/Ph20sr/php-migrate/actions/workflows/ci.yml/badge.svg)](https://github.com/Ph20sr/php-migrate/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4)
![license](https://img.shields.io/badge/license-MIT-blue)

Migrations em **SQL puro** para projetos PHP sem framework: APIs em hospedagem compartilhada, painéis internos, sistemas legados. Basta PDO, sem Laravel nem Doctrine.

Cada migration é um arquivo `.sql` versionado no git. O banco guarda o que já foi aplicado e o checksum de cada arquivo.

## Por que usar

| problema comum | o que o php-migrate faz |
| --- | --- |
| alguém edita uma migration já aplicada em produção | **checksum**: o `up` recusa rodar e o `status` mostra `changed` |
| dois deploys rodam migrations ao mesmo tempo | **lock** com `GET_LOCK` no MySQL |
| branch antiga traz uma migration com data anterior | recusa por padrão (`--allow-out-of-order` para aceitar) |
| migration falha no meio | roda em transação; no SQLite até o DDL é revertido |
| precisa desfazer | seção opcional `-- migrate:down` |
| quer ver antes de aplicar | `--dry-run` |

## Instalação

```bash
composer config repositories.php-migrate vcs https://github.com/Ph20sr/php-migrate
composer require ph20sr/php-migrate:^1.0
```

## CLI

```bash
export DB_DSN="mysql:host=127.0.0.1;dbname=app;charset=utf8mb4" DB_USER=app DB_PASS=secret

vendor/bin/migrate new "create invoices"   # migrations/20261005_143000_create_invoices.sql
vendor/bin/migrate status
vendor/bin/migrate up --dry-run
vendor/bin/migrate up
vendor/bin/migrate down 1
```

```
✔ 20261001090000 create_customers        applied
✔ 20261002100000 create_invoices         applied
· 20261005143000 add_status_to_invoices  pending
```

`status` termina com código 1 se houver `changed` ou `missing`, então pode servir de checagem no CI.

## Formato

`migrations/20261005_143000_add_status_to_invoices.sql`

```sql
ALTER TABLE invoices ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending';
CREATE INDEX idx_invoices_status ON invoices (status);

-- migrate:down
DROP INDEX idx_invoices_status ON invoices;
ALTER TABLE invoices DROP COLUMN status;
```

O checksum ignora diferenças de espaço e quebra de linha, então reformatar o arquivo não dispara o alerta.

## Em código

```php
use Ph20sr\Migrate\Migrator;

$migrator = new Migrator($pdo, __DIR__ . '/migrations', log: fn ($line) => error_log($line));

$migrator->status();     // [['version' => ..., 'name' => ..., 'state' => 'applied|pending|changed|missing', ...]]
$migrator->migrate();    // ids aplicados
$migrator->rollback(1);  // ids revertidos
```

Útil, por exemplo, para rodar no deploy por um endpoint protegido quando a hospedagem não dá acesso SSH.

## MySQL e transações

No MySQL, `CREATE`/`ALTER`/`DROP` fazem commit implícito. A transação protege migrations de dados (DML), mas não desfaz DDL parcial. Prefira **uma alteração de schema por arquivo**. Assim, uma falha deixa o banco num estado conhecido e a migration continua `pending`.

## Testes

```bash
composer install
vendor/bin/phpunit
```

Os testes rodam em SQLite em memória, com arquivos temporários.

## Licença

MIT
