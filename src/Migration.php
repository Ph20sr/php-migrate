<?php

declare(strict_types=1);

namespace Ph20sr\Migrate;

/**
 * Um arquivo de migration: <versão>_<nome>.sql
 *
 * O arquivo pode ter uma seção de rollback separada pelo marcador
 * "-- migrate:down". Tudo antes dele é o "up".
 */
final class Migration
{
    public const DOWN_MARKER = '-- migrate:down';
    private const FILE_PATTERN = '/^(\d{8}_?\d{6})_([a-z0-9_]+)\.sql$/';

    public function __construct(
        public readonly string $version,
        public readonly string $name,
        public readonly string $up,
        public readonly ?string $down,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!preg_match(self::FILE_PATTERN, basename($path), $m)) {
            throw new MigrationException(sprintf(
                'Nome de migration inválido: %s (esperado AAAAMMDD_HHMMSS_nome.sql)',
                basename($path),
            ));
        }
        $sql = str_replace("\r\n", "\n", (string) file_get_contents($path));
        $parts = preg_split('/^' . preg_quote(self::DOWN_MARKER, '/') . '\s*$/m', $sql, 2);
        $down = isset($parts[1]) ? trim($parts[1]) : null;

        return new self(str_replace('_', '', $m[1]), $m[2], trim($parts[0]), $down === '' ? null : $down);
    }

    /** Hash do "up" normalizado: detecta edição de uma migration já aplicada. */
    public function checksum(): string
    {
        return hash('sha256', preg_replace('/\s+/', ' ', $this->up));
    }

    public function id(): string
    {
        return "{$this->version}_{$this->name}";
    }
}
