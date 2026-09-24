<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\TransactionManagerInterface;
use Quantum\Database\Telemetry\DatabaseTelemetryEmitter;
use Throwable;

final class TransactionManager implements TransactionManagerInterface
{
    /**
     * @var array<string, TransactionContext>
     */
    private array $contexts = [];

    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseTelemetryEmitter $telemetry,
    ) {
    }

    public function begin(?string $connectionName = null): TransactionContext
    {
        $connection = $this->connections->connection($connectionName);
        $target = $connection->name();
        $context = $this->contexts[$target] ?? null;

        if ($context === null) {
            $connection->pdo()->beginTransaction();

            $context = $this->contexts[$target] = new TransactionContext(
                TransactionId::generate(),
                $target,
            );

            $this->telemetry->transactionBegan($context);

            return $context;
        }

        if (! $connection->platform()->capabilities()->supports('savepoints')) {
            throw new TransactionException(sprintf(
                'Nested transactions are not supported for database connection [%s] because savepoints are unavailable.',
                $target,
            ));
        }

        $savepoint = $this->savepointName($context);
        $connection->pdo()->exec('SAVEPOINT ' . $savepoint);
        $context->beginNested($savepoint);

        return $context;
    }

    public function commit(?string $connectionName = null): void
    {
        $connection = $this->connections->connection($connectionName);
        $target = $connection->name();
        $context = $this->requireContext($target);

        if ($context->rollbackOnly()) {
            $this->rollback($target);

            throw new TransactionException(sprintf(
                'Database transaction [%s] on connection [%s] is marked rollback-only and cannot be committed.',
                $context->id()->value,
                $target,
            ));
        }

        if ($context->depth() > 1) {
            $savepoint = $context->releaseNested();
            $connection->pdo()->exec('RELEASE SAVEPOINT ' . $savepoint);

            return;
        }

        $connection->pdo()->commit();
        $context->completeCommitted();
        $this->telemetry->transactionCommitted($context);
        unset($this->contexts[$target]);
    }

    public function rollback(?string $connectionName = null): void
    {
        $connection = $this->connections->connection($connectionName);
        $target = $connection->name();
        $context = $this->requireContext($target);

        if ($context->depth() > 1) {
            $savepoint = $context->rollbackNested();
            $connection->pdo()->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $connection->pdo()->exec('RELEASE SAVEPOINT ' . $savepoint);

            return;
        }

        if ($connection->pdo()->inTransaction()) {
            $connection->pdo()->rollBack();
        }

        $context->completeRolledBack();
        $this->telemetry->transactionRolledBack($context);
        unset($this->contexts[$target]);
    }

    public function markRollbackOnly(?string $connectionName = null): void
    {
        $target = $this->connections->connection($connectionName)->name();
        $context = $this->requireContext($target);
        $context->markRollbackOnly();
        $this->telemetry->transactionMarkedRollbackOnly($context);
    }

    public function current(?string $connectionName = null): ?TransactionContext
    {
        $target = $this->connections->connection($connectionName)->name();

        return $this->contexts[$target] ?? null;
    }

    public function isActive(?string $connectionName = null): bool
    {
        $context = $this->current($connectionName);

        return $context !== null && $context->isActive();
    }

    public function rollbackOpenTransactions(): void
    {
        foreach (array_keys($this->contexts) as $connectionName) {
            while (isset($this->contexts[$connectionName]) && $this->contexts[$connectionName]->isActive()) {
                $this->rollback($connectionName);
            }
        }
    }

    public function transaction(callable $callback, ?string $connectionName = null): mixed
    {
        $context = $this->begin($connectionName);

        try {
            $result = $callback(
                $this->connections->connection($context->connectionName()),
                $context,
            );
        } catch (Throwable $throwable) {
            if ($this->isActive($context->connectionName())) {
                $this->rollback($context->connectionName());
            }

            throw $throwable;
        }

        $this->commit($context->connectionName());

        return $result;
    }

    private function requireContext(string $connectionName): TransactionContext
    {
        $context = $this->contexts[$connectionName] ?? null;

        if ($context === null || ! $context->isActive()) {
            throw new TransactionException(sprintf(
                'No active database transaction exists for connection [%s].',
                $connectionName,
            ));
        }

        return $context;
    }

    private function savepointName(TransactionContext $context): string
    {
        return sprintf(
            'quantum_sp_%s_%d',
            substr($context->id()->value, 0, 12),
            $context->depth(),
        );
    }
}
