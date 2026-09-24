<?php

declare(strict_types=1);

namespace Quantum\Database\Driver;

use PDO;
use PDOException;
use Quantum\Database\Connection\ConnectionDefinition;
use Quantum\Database\Contracts\DriverInterface;
use Quantum\Database\Contracts\NativeConnectionInterface;
use RuntimeException;

final class PdoDriver implements DriverInterface
{
    public function __construct(
        private readonly string $idValue,
    ) {
    }

    public function id(): string
    {
        return $this->idValue;
    }

    public function connect(ConnectionDefinition $definition): NativeConnectionInterface
    {
        $driver = strtolower($definition->driver);
        $dsn = $this->buildDsn($definition, $driver);

        try {
            $pdo = new PDO(
                $dsn,
                $definition->username,
                $definition->password,
                $this->pdoOptions($definition),
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Unable to establish database connection [%s] using driver [%s].', $definition->name, $definition->driver),
                previous: $exception,
            );
        }

        return new PdoNativeConnection($pdo);
    }

    private function buildDsn(ConnectionDefinition $definition, string $driver): string
    {
        return match ($driver) {
            'sqlite', 'sqlite3', 'pdo.sqlite' => 'sqlite:' . $definition->database,
            'pgsql', 'postgres', 'postgresql', 'pdo.pgsql' => $this->buildPgsqlDsn($definition),
            'mysql', 'mariadb', 'pdo.mysql' => $this->buildMysqlDsn($definition),
            default => throw new RuntimeException(sprintf('Unsupported PDO driver [%s].', $definition->driver)),
        };
    }

    private function buildPgsqlDsn(ConnectionDefinition $definition): string
    {
        $parts = ['dbname=' . $definition->database];

        if ($definition->host !== null) {
            $parts[] = 'host=' . $definition->host;
        }

        if ($definition->port !== null) {
            $parts[] = 'port=' . $definition->port;
        }

        return 'pgsql:' . implode(';', $parts);
    }

    private function buildMysqlDsn(ConnectionDefinition $definition): string
    {
        $parts = ['dbname=' . $definition->database];

        if ($definition->host !== null) {
            $parts[] = 'host=' . $definition->host;
        }

        if ($definition->port !== null) {
            $parts[] = 'port=' . $definition->port;
        }

        if ($definition->charset !== null) {
            $parts[] = 'charset=' . $definition->charset;
        }

        return 'mysql:' . implode(';', $parts);
    }

    /**
     * @return array<int, mixed>
     */
    private function pdoOptions(ConnectionDefinition $definition): array
    {
        $defaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($definition->platformId() === 'sqlite') {
            $defaults[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        foreach ($definition->options as $key => $value) {
            if (is_int($key)) {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }
}
