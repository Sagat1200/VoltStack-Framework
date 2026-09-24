<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

use Quantum\Database\Transaction\TransactionContext;

interface TransactionManagerInterface
{
    public function begin(?string $connectionName = null): TransactionContext;

    public function commit(?string $connectionName = null): void;

    public function rollback(?string $connectionName = null): void;

    public function markRollbackOnly(?string $connectionName = null): void;

    public function current(?string $connectionName = null): ?TransactionContext;

    public function isActive(?string $connectionName = null): bool;

    public function rollbackOpenTransactions(): void;

    public function transaction(callable $callback, ?string $connectionName = null): mixed;
}
