<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

use Quantum\Database\Transaction\TransactionContext;
use Quantum\Database\Transaction\TransactionDefinition;
use Quantum\Database\Transaction\TransactionRetryCoordinator;

interface TransactionManagerInterface
{
    /**
     * Begin a transaction or participate in an existing one.
     *
     * The first argument may be a TransactionDefinition or a connection name
     * string (kept for backward compatibility).
     *
     * @param TransactionDefinition|string|null $definitionOrConnection
     */
    public function begin(
        TransactionDefinition|string|null $definitionOrConnection = null,
        ?string $connectionName = null,
    ): TransactionContext;

    /**
     * @param TransactionDefinition|string|null $connectionName
     */
    public function commit(TransactionDefinition|string|null $connectionName = null): void;

    /**
     * @param TransactionDefinition|string|null $connectionName
     */
    public function rollback(TransactionDefinition|string|null $connectionName = null): void;

    /**
     * @param TransactionDefinition|string|null $connectionName
     */
    public function markRollbackOnly(TransactionDefinition|string|null $connectionName = null): void;

    /**
     * @param TransactionDefinition|string|null $connectionName
     */
    public function current(TransactionDefinition|string|null $connectionName = null): ?TransactionContext;

    /**
     * @param TransactionDefinition|string|null $connectionName
     */
    public function isActive(TransactionDefinition|string|null $connectionName = null): bool;

    public function rollbackOpenTransactions(): void;

    /**
     * Execute $callback inside a transaction. If $definition includes a retry
     * policy, the whole unit is retried on transient failures.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(
        callable $callback,
        ?string $connectionName = null,
        ?TransactionDefinition $definition = null,
    ): mixed;

    public function retryCoordinator(): ?TransactionRetryCoordinator;
}
