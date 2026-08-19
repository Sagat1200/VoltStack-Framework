<?php

declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\Sqlite;

use Quantum\Database\Dbal\Driver\PdoCommon\AbstractPdoConnection;

final class PdoSqliteConnection extends AbstractPdoConnection
{
    public function __construct(array $config)
    {
        parent::__construct(
            config: $config,
            mapper: new SqliteExceptionMapper(),
            driverName: 'sqlite',
            quoteCharacter: '"',
            supportsSavepoints: true,
            paramStyle: 'positional_q',
        );
    }

    protected function connectInternal(array $config): \PDO
    {
        $path = $config['path'] ?? ($config['memory'] ? ':memory:' : null);
        if ($path === null) {
            throw new \RuntimeException('SQLite config requires "path" or "memory"=>true');
        }
        $dsn = "sqlite:{$path}";
        $pdo = new \PDO($dsn, options: ($config['options'] ?? null) ?? []);
        return $pdo;
    }

    protected function onAfterConnect(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // SQLite with native prepared statements + exec() for tx control causes
        // "SQL statements in progress" — force emulate prepares for SQLite.
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if (!empty($this->config['journal_mode'])) {
            $mode = $this->config['journal_mode'];
            $pdo->exec("PRAGMA journal_mode = {$mode}");
        }
        if (!empty($this->config['synchronous'])) {
            $s = $this->config['synchronous'];
            $pdo->exec("PRAGMA synchronous = {$s}");
        }
        if (!empty($this->config['busy_timeout_ms'])) {
            $ms = (int)$this->config['busy_timeout_ms'];
            $pdo->exec("PRAGMA busy_timeout = {$ms}");
        }
    }

    protected function sqlSetTxIsolation(string $levelValue): string
    {
        // SQLite solo soporta SERIALIZABLE (default) y READ UNCOMMITTED.
        // Resto de niveles se mapea a no-op PRAGMA para no fallar V1 smoke.
        return match (true) {
            str_ends_with($levelValue, 'UNCOMMITTED') => 'PRAGMA read_uncommitted = 1',
            default => 'PRAGMA read_uncommitted = 0',
        };
    }

    protected function isConnectivity(\Throwable $t): bool
    {
        $m = strtolower($t->getMessage());
        return str_contains($m, 'unable to open') || str_contains($m, 'permission denied');
    }

    protected function isRetryableConnect(\Throwable $t): bool
    {
        return false;
    }
}