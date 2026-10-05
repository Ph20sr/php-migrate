<?php

declare(strict_types=1);

namespace Ph20sr\Migrate;

use PDO;

final class Migrator
{
    private const LOCK_NAME = 'php_migrate_lock';

    private readonly string $driver;
    /** @var \Closure(string): void */
    private \Closure $log;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
        private readonly string $table = 'schema_migrations',
        ?callable $log = null,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Nome de tabela inválido: {$table}");
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->log = $log !== null ? \Closure::fromCallable($log) : static function (string $message): void {
        };
    }

    /** @return list<Migration> ordenadas por versão */
    public function available(): array
    {
        $files = glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [];
        $migrations = array_map(Migration::fromFile(...), $files);
        usort($migrations, static fn (Migration $a, Migration $b): int => strcmp($a->version, $b->version));

        $seen = [];
        foreach ($migrations as $m) {
            if (isset($seen[$m->version])) {
                throw new MigrationException("Versão duplicada {$m->version}: {$seen[$m->version]} e {$m->id()}");
            }
            $seen[$m->version] = $m->id();
        }
        return $migrations;
    }

    /** @return array<string, array{name: string, checksum: string, applied_at: string}> por versão */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query("SELECT version, name, checksum, applied_at FROM {$this->table} ORDER BY version")
            ->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['version']] = [
                'name' => (string) $row['name'],
                'checksum' => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }
        return $result;
    }

    /** @return list<Migration> */
    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter($this->available(), static fn (Migration $m): bool => !isset($applied[$m->version])));
    }

    /**
     * Situação de cada migration: applied, pending, changed (editada após
     * aplicada) ou missing (aplicada no banco, mas o arquivo sumiu).
     *
     * @return list<array{version: string, name: string, state: string, applied_at: ?string}>
     */
    public function status(): array
    {
        $applied = $this->applied();
        $rows = [];
        foreach ($this->available() as $m) {
            $row = $applied[$m->version] ?? null;
            $state = match (true) {
                $row === null => 'pending',
                !hash_equals($row['checksum'], $m->checksum()) => 'changed',
                default => 'applied',
            };
            $rows[] = ['version' => $m->version, 'name' => $m->name, 'state' => $state, 'applied_at' => $row['applied_at'] ?? null];
            unset($applied[$m->version]);
        }
        foreach ($applied as $version => $row) {
            $rows[] = ['version' => (string) $version, 'name' => $row['name'], 'state' => 'missing', 'applied_at' => $row['applied_at']];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['version'], $b['version']));
        return $rows;
    }

    /**
     * Aplica as migrations pendentes em ordem. Recusa rodar se alguma já
     * aplicada foi editada, ou se há pendente mais antiga que a última
     * aplicada (provável conflito de branch), a menos que $allowOutOfOrder.
     *
     * @return list<string> ids aplicados
     */
    public function migrate(bool $allowOutOfOrder = false, bool $dryRun = false): array
    {
        return $this->withLock(function () use ($allowOutOfOrder, $dryRun): array {
            $this->assertNoChanges();
            $pending = $this->pending();
            $applied = array_keys($this->applied());
            $last = $applied === [] ? null : max(array_map('strval', $applied));

            if (!$allowOutOfOrder && $last !== null) {
                foreach ($pending as $m) {
                    if (strcmp($m->version, $last) < 0) {
                        throw new MigrationException(
                            "A migration {$m->id()} é anterior à última aplicada ({$last}). " .
                            'Renomeie-a com uma versão nova ou use --allow-out-of-order.',
                        );
                    }
                }
            }

            $done = [];
            foreach ($pending as $m) {
                ($this->log)(($dryRun ? '[dry-run] ' : '') . "↑ {$m->id()}");
                if (!$dryRun) {
                    $this->run($m->up, function () use ($m): void {
                        $this->pdo->prepare("INSERT INTO {$this->table} (version, name, checksum, applied_at) VALUES (?, ?, ?, ?)")
                            ->execute([$m->version, $m->name, $m->checksum(), gmdate('Y-m-d H:i:s')]);
                    });
                }
                $done[] = $m->id();
            }
            return $done;
        });
    }

    /**
     * Desfaz as últimas $steps migrations aplicadas usando a seção down.
     *
     * @return list<string> ids revertidos
     */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new \InvalidArgumentException('steps deve ser >= 1');
        }
        return $this->withLock(function () use ($steps): array {
            $byVersion = [];
            foreach ($this->available() as $m) {
                $byVersion[$m->version] = $m;
            }
            $versions = array_map('strval', array_reverse(array_keys($this->applied())));

            $done = [];
            foreach (array_slice($versions, 0, $steps) as $version) {
                $m = $byVersion[$version] ?? throw new MigrationException("Arquivo da migration {$version} não encontrado");
                if ($m->down === null) {
                    throw new MigrationException("A migration {$m->id()} não tem seção " . Migration::DOWN_MARKER);
                }
                ($this->log)("↓ {$m->id()}");
                $this->run($m->down, function () use ($m): void {
                    $this->pdo->prepare("DELETE FROM {$this->table} WHERE version = ?")->execute([$m->version]);
                });
                $done[] = $m->id();
            }
            return $done;
        });
    }

    /** Cria um arquivo de migration vazio com a versão atual (UTC). */
    public function create(string $name, ?\DateTimeImmutable $now = null): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        if ($slug === '') {
            throw new \InvalidArgumentException('Informe um nome para a migration');
        }
        $version = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd_His');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new MigrationException("Não foi possível criar {$this->directory}");
        }
        $path = rtrim($this->directory, '/\\') . "/{$version}_{$slug}.sql";
        if (file_exists($path)) {
            throw new MigrationException("Já existe {$path}");
        }
        file_put_contents($path, "-- {$name}\n\n\n" . Migration::DOWN_MARKER . "\n\n");
        return $path;
    }

    private function assertNoChanges(): void
    {
        $changed = array_filter($this->status(), static fn (array $r): bool => $r['state'] === 'changed');
        if ($changed !== []) {
            $ids = implode(', ', array_map(static fn (array $r): string => "{$r['version']}_{$r['name']}", $changed));
            throw new MigrationException(
                "Migrations já aplicadas foram editadas: {$ids}. Crie uma nova migration em vez de alterar as antigas.",
            );
        }
    }

    /**
     * Executa o SQL e o registro na tabela de controle. Em SQLite (e em
     * PostgreSQL) DDL é transacional. No MySQL, DDL faz commit implícito,
     * então a transação só protege migrations de dados (DML).
     */
    private function run(string $sql, callable $record): void
    {
        $this->pdo->beginTransaction();
        try {
            if (trim($sql) !== '') {
                $this->pdo->exec($sql);
            }
            $record();
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new MigrationException('Falha ao executar migration: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $this->ensureTable();
        if ($this->driver !== 'mysql') {
            return $callback();
        }
        $got = (int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 10)")->fetchColumn();
        if ($got !== 1) {
            throw new MigrationException('Outra execução de migrations está em andamento');
        }
        try {
            return $callback();
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                version VARCHAR(20) NOT NULL PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL
            )",
        );
    }
}
