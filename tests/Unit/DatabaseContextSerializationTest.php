<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Security\DatabaseSecurityContext;

final class DatabaseContextSerializationTest extends TestCase
{
    public function test_it_serializes_without_connection_or_secret_leakage(): void
    {
        $context = new DatabaseContext(
            requestId: 'req-123',
            connection: new class('secret-phase9-test') implements \Quantum\Database\Dbal\Contract\ConnectionInterface {
                public function __construct(private readonly string $password) {}
                public function connect(): void {}
                public function isConnected(): bool { return true; }
                public function close(): void {}
                public function ping(): bool { return true; }
                public function prepare(string $sql): \Quantum\Database\Dbal\Contract\StatementInterface { throw new \BadMethodCallException(); }
                public function executeStatement(string $sql, array $params = []): \Quantum\Database\Dbal\Value\QueryResult { throw new \BadMethodCallException(); }
                public function executeQuery(string $sql, array $params = []): \Quantum\Database\Dbal\Value\QueryResult { throw new \BadMethodCallException(); }
                public function lastInsertId(?string $sequenceName = null): string|int|null { return null; }
                public function quoteIdentifier(string $identifier): string { return $identifier; }
                public function quoteString(string $value): string { return $value; }
                public function inTransaction(): bool { return false; }
                public function beginTransaction(): bool { return true; }
                public function commit(): bool { return true; }
                public function rollback(): bool { return true; }
                public function createSavepoint(string $identifier): void {}
                public function releaseSavepoint(string $identifier): void {}
                public function rollbackToSavepoint(string $identifier): void {}
                public function setTransactionIsolation(\Quantum\Database\Dbal\Enum\TransactionIsolation $level): void {}
                public function getDriverInfo(): \Quantum\Database\Dbal\Value\DriverInfo { return new \Quantum\Database\Dbal\Value\DriverInfo('sqlite', 'test', 'db'); }
                public function getCapabilities(): \Quantum\Database\Capability\DatabaseCapabilitySet { return \Quantum\Database\Capability\DatabaseCapabilitySet::minimalSet(); }
                public function lastUsedAtSeconds(): float { return 0.0; }
                public function getNativeHandle(): mixed { return $this->password; }
            },
            tenantId: 'tenant-1',
            security: new DatabaseSecurityContext(subjectId: 'user-1', roles: ['admin']),
        );

        $serialized = serialize($context);
        /** @var DatabaseContext $restored */
        $restored = unserialize($serialized, ['allowed_classes' => true]);

        self::assertFalse(str_contains($serialized, 'secret-phase9-test'));
        self::assertInstanceOf(DatabaseContext::class, $restored);
        self::assertSame('req-123', $restored->requestId);
        self::assertNull($restored->connection);
        self::assertSame('tenant-1', $restored->tenantId);
    }
}
