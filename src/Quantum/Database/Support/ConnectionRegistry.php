<?php

declare(strict_types=1);

namespace Quantum\Database\Support;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Driver\Mariadb\PdoMariadbConnection;
use Quantum\Database\Dbal\Driver\Mysql\PdoMysqlConnection;
use Quantum\Database\Dbal\Driver\Pgsql\PdoPgsqlConnection;
use Quantum\Database\Dbal\Driver\Sqlite\PdoSqliteConnection;
use Quantum\Database\Dialect\DialectInterface;
use Quantum\Database\Dialect\Support\DialectFactory;

final class ConnectionRegistry
{
    /** @var array<string,ConnectionInterface> */
    private array $resolvedConnections = [];

    /** @var array<string,DialectInterface> */
    private array $dialects = [];

    /**
     * @param array<string,array<string,mixed>> $connections
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $defaultConnection,
        private readonly array $connectionConfigs,
    ) {}

    public function defaultConnectionName(): string
    {
        return $this->defaultConnection;
    }

    /**
     * @return list<string>
     */
    public function connectionNames(): array
    {
        return array_values(array_keys($this->connectionConfigs));
    }

    /**
     * @return array<string,mixed>
     */
    public function config(?string $name = null): array
    {
        $resolved = $this->resolveName($name);
        $config = $this->connectionConfigs[$resolved] ?? null;

        if ($config === null) {
            throw new \InvalidArgumentException(sprintf('Database connection [%s] is not configured.', $resolved));
        }

        return $config;
    }

    public function connection(?string $name = null): ConnectionInterface
    {
        $resolved = $this->resolveName($name);

        if (isset($this->resolvedConnections[$resolved])) {
            return $this->resolvedConnections[$resolved];
        }

        $config = $this->config($resolved);
        $driver = strtolower((string) ($config['driver'] ?? ''));

        $connection = match ($driver) {
            'pgsql' => new PdoPgsqlConnection($config),
            'mysql' => new PdoMysqlConnection($config),
            'mariadb' => new PdoMariadbConnection($config),
            'sqlite' => new PdoSqliteConnection($this->normalizeSqliteConfig($config)),
            default => throw new \RuntimeException(sprintf('Database driver [%s] is not supported.', $driver)),
        };

        $this->resolvedConnections[$resolved] = $connection;

        return $connection;
    }

    public function dialect(?string $name = null): DialectInterface
    {
        $resolved = $this->resolveName($name);

        if (isset($this->dialects[$resolved])) {
            return $this->dialects[$resolved];
        }

        $driverName = $this->connection($resolved)->getDriverInfo()->driverName;
        return $this->dialects[$resolved] = DialectFactory::forDriver($driverName);
    }

    private function resolveName(?string $name): string
    {
        $resolved = $name;
        if ($resolved === null || trim($resolved) === '') {
            $resolved = $this->defaultConnection;
        }

        return trim($resolved);
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function normalizeSqliteConfig(array $config): array
    {
        if (($config['memory'] ?? false) === true) {
            return $config;
        }

        $path = $config['path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            return $config;
        }

        $resolved = $this->normalizePath($path);
        $directory = dirname($resolved);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $config['path'] = $resolved;
        return $config;
    }

    private function normalizePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return rtrim($this->basePath, '\\/') . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}
