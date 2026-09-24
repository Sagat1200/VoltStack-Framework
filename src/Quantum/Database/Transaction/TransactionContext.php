<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

final class TransactionContext
{
    /**
     * @var list<string>
     */
    private array $savepoints = [];

    private int $depth = 1;

    private TransactionState $state = TransactionState::Active;

    public function __construct(
        private readonly TransactionId $id,
        private readonly string $connectionName,
    ) {
    }

    public function id(): TransactionId
    {
        return $this->id;
    }

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function state(): TransactionState
    {
        return $this->state;
    }

    public function isActive(): bool
    {
        return in_array($this->state, [TransactionState::Active, TransactionState::RollbackOnly], true);
    }

    public function rollbackOnly(): bool
    {
        return $this->state === TransactionState::RollbackOnly;
    }

    public function beginNested(string $savepoint): void
    {
        $this->savepoints[] = $savepoint;
        $this->depth++;
    }

    public function releaseNested(): string
    {
        $savepoint = array_pop($this->savepoints);

        if (! is_string($savepoint)) {
            throw new TransactionException('No nested transaction savepoint is available to release.');
        }

        $this->depth--;

        return $savepoint;
    }

    public function rollbackNested(): string
    {
        $savepoint = array_pop($this->savepoints);

        if (! is_string($savepoint)) {
            throw new TransactionException('No nested transaction savepoint is available to rollback.');
        }

        $this->depth--;

        return $savepoint;
    }

    public function markRollbackOnly(): void
    {
        if (! $this->isActive()) {
            throw new TransactionException('Cannot mark a completed transaction as rollback-only.');
        }

        $this->state = TransactionState::RollbackOnly;
    }

    public function completeCommitted(): void
    {
        $this->depth = 0;
        $this->savepoints = [];
        $this->state = TransactionState::Committed;
    }

    public function completeRolledBack(): void
    {
        $this->depth = 0;
        $this->savepoints = [];
        $this->state = TransactionState::RolledBack;
    }
}
