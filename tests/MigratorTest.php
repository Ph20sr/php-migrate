<?php

declare(strict_types=1);

namespace Ph20sr\Migrate\Tests;

use PDO;
use Ph20sr\Migrate\Migration;
use Ph20sr\Migrate\MigrationException;
use Ph20sr\Migrate\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private string $dir;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-migrate-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->pdo = new PDO('sqlite::memory:');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function write(string $file, string $sql): void
    {
        file_put_contents("{$this->dir}/{$file}", $sql);
    }

    private function tables(): array
    {
        return $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private function seed(): void
    {
        $this->write('20261001_090000_create_customers.sql', <<<SQL
            CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
            CREATE INDEX idx_customers_name ON customers (name);

            -- migrate:down
            DROP TABLE customers;
            SQL);
        $this->write('20261002_100000_create_invoices.sql', <<<SQL
            CREATE TABLE invoices (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, total INTEGER NOT NULL);
            -- migrate:down
            DROP TABLE invoices;
            SQL);
    }

    public function testParsesFileWithDownSection(): void
    {
        $this->seed();
        $m = Migration::fromFile("{$this->dir}/20261001_090000_create_customers.sql");

        $this->assertSame('20261001090000', $m->version);
        $this->assertSame('create_customers', $m->name);
        $this->assertStringContainsString('CREATE INDEX', $m->up);
        $this->assertSame('DROP TABLE customers;', $m->down);
    }

    public function testRejectsBadFileNames(): void
    {
        $this->write('create_users.sql', 'SELECT 1;');
        $this->expectException(MigrationException::class);
        (new Migrator($this->pdo, $this->dir))->available();
    }

    public function testMigratesInOrderAndIsIdempotent(): void
    {
        $this->seed();
        $log = [];
        $migrator = new Migrator($this->pdo, $this->dir, log: function (string $line) use (&$log): void {
            $log[] = $line;
        });

        $this->assertSame(['20261001090000_create_customers', '20261002100000_create_invoices'], $migrator->migrate());
        $this->assertSame(['customers', 'invoices', 'schema_migrations'], $this->tables());
        $this->assertSame([], $migrator->migrate(), 'segunda execução não faz nada');
        $this->assertCount(2, $log);
        $this->assertSame(['applied', 'applied'], array_column($migrator->status(), 'state'));
    }

    public function testDryRunDoesNotTouchTheDatabase(): void
    {
        $this->seed();
        $migrator = new Migrator($this->pdo, $this->dir);
        $this->assertCount(2, $migrator->migrate(dryRun: true));
        $this->assertSame(['schema_migrations'], $this->tables());
        $this->assertCount(2, $migrator->pending());
    }

    public function testDetectsEditedMigrationAndRefusesToRun(): void
    {
        $this->seed();
        $migrator = new Migrator($this->pdo, $this->dir);
        $migrator->migrate();

        // Só espaços/quebras de linha diferentes não contam como edição
        $this->write('20261002_100000_create_invoices.sql', "CREATE TABLE invoices (id INTEGER PRIMARY KEY,   customer_id INTEGER NOT NULL, total INTEGER NOT NULL);\n-- migrate:down\nDROP TABLE invoices;");
        $this->assertSame('applied', $migrator->status()[1]['state']);

        $this->write('20261002_100000_create_invoices.sql', "CREATE TABLE invoices (id INTEGER PRIMARY KEY);\n-- migrate:down\nDROP TABLE invoices;");
        $this->write('20261003_080000_add_status.sql', 'ALTER TABLE invoices ADD COLUMN status TEXT;');
        $this->assertSame(['applied', 'changed', 'pending'], array_column($migrator->status(), 'state'));

        $this->expectException(MigrationException::class);
        $migrator->migrate();
    }

    public function testReportsMissingFiles(): void
    {
        $this->seed();
        $migrator = new Migrator($this->pdo, $this->dir);
        $migrator->migrate();
        unlink("{$this->dir}/20261002_100000_create_invoices.sql");

        $this->assertSame(['applied', 'missing'], array_column($migrator->status(), 'state'));
    }

    public function testRefusesOutOfOrderUnlessAllowed(): void
    {
        $this->seed();
        $migrator = new Migrator($this->pdo, $this->dir);
        $migrator->migrate();
        $this->write('20261001_120000_from_old_branch.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');

        try {
            $migrator->migrate();
            $this->fail('Deveria recusar migration fora de ordem');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('anterior à última aplicada', $e->getMessage());
        }
        $this->assertSame(['20261001120000_from_old_branch'], $migrator->migrate(allowOutOfOrder: true));
    }

    public function testFailedMigrationRollsBackAndIsNotRecorded(): void
    {
        $this->seed();
        $this->write('20261003_080000_broken.sql', "CREATE TABLE temp_x (id INTEGER);\nINSERT INTO nope VALUES (1);");
        $migrator = new Migrator($this->pdo, $this->dir);

        try {
            $migrator->migrate();
            $this->fail('Deveria falhar');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('nope', $e->getMessage());
        }
        $this->assertSame(['customers', 'invoices', 'schema_migrations'], $this->tables(), 'DDL revertido no SQLite');
        $this->assertSame(['applied', 'applied', 'pending'], array_column($migrator->status(), 'state'));
    }

    public function testRollbackUsesDownSection(): void
    {
        $this->seed();
        $migrator = new Migrator($this->pdo, $this->dir);
        $migrator->migrate();

        $this->assertSame(['20261002100000_create_invoices'], $migrator->rollback());
        $this->assertSame(['customers', 'schema_migrations'], $this->tables());
        $this->assertSame(['20261001090000_create_customers'], $migrator->rollback(5));
        $this->assertSame(['schema_migrations'], $this->tables());
    }

    public function testRollbackWithoutDownFails(): void
    {
        $this->write('20261001_090000_seed.sql', 'CREATE TABLE a (id INTEGER);');
        $migrator = new Migrator($this->pdo, $this->dir);
        $migrator->migrate();

        $this->expectException(MigrationException::class);
        $migrator->rollback();
    }

    public function testCreateWritesTemplate(): void
    {
        $migrator = new Migrator($this->pdo, $this->dir);
        $path = $migrator->create('Add phone to Customers', new \DateTimeImmutable('2026-10-05 14:30:00'));

        $this->assertSame('20261005_143000_add_phone_to_customers.sql', basename($path));
        $this->assertStringContainsString(Migration::DOWN_MARKER, (string) file_get_contents($path));
        $this->assertCount(1, $migrator->available());
    }

    public function testDuplicateVersionsAreRejected(): void
    {
        $this->write('20261001_090000_a.sql', 'SELECT 1;');
        $this->write('20261001_090000_b.sql', 'SELECT 1;');
        $this->expectException(MigrationException::class);
        (new Migrator($this->pdo, $this->dir))->available();
    }
}
