<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Contract\StatementInterface;
use Quantum\Database\Dbal\Enum\TransactionIsolation;
use Quantum\Database\Dbal\Value\DriverInfo;
use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Support\ConnectionRegistry;

final class ConnectionRegistryTest extends TestCase
{
    public function test_it_refreshes_only_resolved_connections_based_on_idle_time(): void
    {
        $fresh = new TestConnection(isConnected: true, lastUsedAtSeconds: hrtime(true) / 1_000_000_000, pingResult: true);
        $staleHealthy = new TestConnection(isConnected: true, lastUsedAtSeconds: 0.0, pingResult: true);
        $staleBroken = new TestConnection(isConnected: true, lastUsedAtSeconds: 0.0, pingResult: false);
        $disconnected = new TestConnection(isConnected: false, lastUsedAtSeconds: 0.0, pingResult: false);

        $registry = new ConnectionRegistry(
            basePath: sys_get_temp_dir(),
            defaultConnection: 'primary',
            connectionConfigs: [],
        );

        $reflection = new \ReflectionProperty($registry, 'resolvedConnections');
        $reflection->setAccessible(true);
        $reflection->setValue($registry, [
            'fresh' => $fresh,
            'stale_healthy' => $staleHealthy,
            'stale_broken' => $staleBroken,
            'disconnected' => $disconnected,
        ]);

        $registry->refreshIdleConnections(1000);

        self::assertSame(0, $fresh->pingCalls);
        self::assertSame(0, $fresh->connectCalls);

        self::assertSame(1, $staleHealthy->pingCalls);
        self::assertSame(0, $staleHealthy->connectCalls);

        self::assertSame(1, $staleBroken->pingCalls);
        self::assertSame(1, $staleBroken->closeCalls);
        self::assertSame(1, $staleBroken->connectCalls);

        self::assertSame(0, $disconnected->pingCalls);
        self::assertSame(1, $disconnected->connectCalls);
    }
}

final class TestConnection implements ConnectionInterface
{
    public int $connectCalls = 0;
    public int $closeCalls = 0;
    public int $pingCalls = 0;

    public function __construct(
        private bool $isConnected,
        private float $lastUsedAtSeconds,
        private readonly bool $pingResult,
    ) {
    }

    public function connect(): void
    {
        $this->connectCalls++;
        $this->isConnected = true;
        $this->lastUsedAtSeconds = hrtime(true) / 1_000_000_000;
    }

    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    public function close(): void
    {
        $this->closeCalls++;
        $this->isConnected = false;
    }

    public function ping(): bool
    {
        $this->pingCalls++;
        $this->isConnected = $this->pingResult;
        if ($this->pingResult) {
            $this->lastUsedAtSeconds = hrtime(true) / 1_000_000_000;
        }

        return $this->pingResult;
    }

    public function prepare(string $sql): StatementInterface
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function executeStatement(string $sql, array $params = []): QueryResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function executeQuery(string $sql, array $params = []): QueryResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function lastInsertId(?string $sequenceName = null): string|int|null
    {
        return null;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $identifier;
    }

    public function quoteString(string $value): string
    {
        return "'" . $value . "'";
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollback(): bool
    {
        return true;
    }

    public function createSavepoint(string $identifier): void
    {
    }

    public function releaseSavepoint(string $identifier): void
    {
    }

    public function rollbackToSavepoint(string $identifier): void
    {
    }

    public function setTransactionIsolation(TransactionIsolation $level): void
    {
    }

    public function getDriverInfo(): DriverInfo
    {
        return new DriverInfo(
            driverName: 'sqlite',
            serverVersion: 'test',
            databaseName: 'test',
        );
    }

    public function getCapabilities(): DatabaseCapabilitySet
    {
        return DatabaseCapabilitySet::minimalSet();
    }

    public function lastUsedAtSeconds(): float
    {
        return $this->lastUsedAtSeconds;
    }

    public function getNativeHandle(): mixed
    {
        return null;
    }
}
