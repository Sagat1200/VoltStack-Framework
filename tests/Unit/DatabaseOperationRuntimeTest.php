<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Contract\StatementInterface;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind;
use Quantum\Database\Dbal\Enum\TransactionIsolation;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\Value\DriverInfo;
use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Operation\DatabaseCircuitBreaker;
use Quantum\Database\Operation\DatabaseExecutionPolicy;
use Quantum\Database\Operation\DatabaseOperationException;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\RawOperation;

final class DatabaseOperationRuntimeTest extends TestCase
{
    public function test_runtime_retries_transient_raw_query_until_success(): void
    {
        $connection = new RuntimeTestConnection([
            DbalException::wrap(new \RuntimeException('temporary disconnect'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'SELECT 1', true),
            RuntimeTestConnection::queryResult([
                ['value' => 1],
            ]),
        ]);
        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker());
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(retryLimit: 1, retryBackoffMs: 0, circuitFailureThreshold: 3);
        $plan = $runtime->plan(new RawOperation(OperationKind::RawQuery, 'SELECT 1', [], 'primary'), $context, $policy);

        $result = $runtime->execute($plan, $context);
        /** @var \Quantum\Database\Operation\DatabaseDiagnosticSnapshot $diagnostic */
        $diagnostic = $result->debug['diagnostic'];

        self::assertTrue($result->isSuccess);
        self::assertSame(2, $diagnostic->attempts);
        self::assertSame('completed', $diagnostic->outcome);
        self::assertSame(2, $connection->queryCalls);
    }

    public function test_runtime_opens_circuit_after_repeated_transient_failures(): void
    {
        $connection = new RuntimeTestConnection([
            DbalException::wrap(new \RuntimeException('temporary disconnect 1'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'SELECT 1', true),
            DbalException::wrap(new \RuntimeException('temporary disconnect 2'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'SELECT 1', true),
        ]);
        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker());
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(retryLimit: 0, retryBackoffMs: 0, circuitFailureThreshold: 2, circuitCooldownMs: 60000);
        $plan = $runtime->plan(new RawOperation(OperationKind::RawQuery, 'SELECT 1', [], 'primary'), $context, $policy);

        try {
            $runtime->execute($plan, $context);
            self::fail('First transient failure should raise an exception.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('transient', $e->failure->value);
        }

        try {
            $runtime->execute($plan, $context);
            self::fail('Second transient failure should raise an exception.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('transient', $e->failure->value);
        }

        try {
            $runtime->execute($plan, $context);
            self::fail('Open circuit should block the third attempt.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('transient', $e->failure->value);
            self::assertSame('open', $e->snapshot->circuitState);
        }

        self::assertSame(2, $connection->queryCalls);
    }
}

final class RuntimeTestConnection implements ConnectionInterface
{
    public int $queryCalls = 0;
    public int $statementCalls = 0;
    private bool $connected = false;

    /**
     * @param list<DbalException|QueryResult> $queryQueue
     */
    public function __construct(
        private array $queryQueue = [],
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public static function queryResult(array $rows, int $affectedRows = 0): QueryResult
    {
        return new QueryResult(
            isSelect: true,
            affectedRows: $affectedRows,
            columnMeta: [],
            rowGenerator: static function () use ($rows): \Generator {
                foreach ($rows as $row) {
                    yield $row;
                }
            },
            cleanup: static function (): void {},
            columnCount: $rows === [] ? 0 : count(array_keys($rows[0])),
        );
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function ping(): bool
    {
        return true;
    }

    public function prepare(string $sql): StatementInterface
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function executeStatement(string $sql, array $params = []): QueryResult
    {
        $this->statementCalls++;

        return new QueryResult(
            isSelect: false,
            affectedRows: 1,
            columnMeta: [],
            rowGenerator: static function (): \Generator {
                if (false) {
                    yield [];
                }
            },
            cleanup: static function (): void {},
            columnCount: 0,
        );
    }

    public function executeQuery(string $sql, array $params = []): QueryResult
    {
        $this->queryCalls++;
        $next = array_shift($this->queryQueue);

        if ($next instanceof DbalException) {
            throw $next;
        }

        if ($next instanceof QueryResult) {
            return $next;
        }

        throw new \RuntimeException('Missing scripted query result.');
    }

    public function lastInsertId(?string $sequenceName = null): string|int|null
    {
        return null;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . $identifier . '"';
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
            databaseName: 'runtime-test',
        );
    }

    public function getCapabilities(): DatabaseCapabilitySet
    {
        return DatabaseCapabilitySet::minimalSet();
    }

    public function lastUsedAtSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function getNativeHandle(): mixed
    {
        return null;
    }
}
