<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

use Quantum\Database\Transaction\Exception\SavepointException;
use Quantum\Database\Transaction\Exception\TransactionOutcomeUnknownException;

/**
 * Authoritative holder of a single transaction's mutable state.
 *
 * TransactionManager holds NO mutable current-transaction state: every live
 * transaction is represented by exactly one TransactionContext scoped to the
 * execution scope that created it.
 *
 * Lifecycle:
 *   New -> Starting -> Active -> (MarkedRollbackOnly) -> Committing -> Committed
 *                                              \-> RollingBack -> RolledBack
 *   Any active state may transition to Failed or Unknown (terminal).
 *
 * Unknown is terminal: once the commit dispatch outcome cannot be confirmed,
 * no further transitions are allowed and rollback/commit raise
 * TransactionOutcomeUnknownException.
 */
final class TransactionContext
{
    private TransactionState $state = TransactionState::Active;

    private CommitDispatchState $commitDispatchState = CommitDispatchState::NotDispatched;

    private readonly SavepointStack $savepoints;

    private readonly CallbackRegistry $callbacks;

    private readonly \DateTimeImmutable $startedAt;

    public function __construct(
        private readonly TransactionId $id,
        private readonly string $connectionName,
        private readonly ?string $scopeId = null,
        private readonly ?string $runtimeRequestId = null,
        private readonly TransactionDefinition $definition = new TransactionDefinition(),
    ) {
        $this->savepoints = new SavepointStack();
        $this->callbacks = new CallbackRegistry();
        $this->startedAt = new \DateTimeImmutable();
    }

    public function id(): TransactionId
    {
        return $this->id;
    }

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function scopeId(): ?string
    {
        return $this->scopeId;
    }

    public function runtimeRequestId(): ?string
    {
        return $this->runtimeRequestId;
    }

    public function definition(): TransactionDefinition
    {
        return $this->definition;
    }

    public function callbacks(): CallbackRegistry
    {
        return $this->callbacks;
    }

    public function commitDispatchState(): CommitDispatchState
    {
        return $this->commitDispatchState;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function depth(): int
    {
        if ($this->state->isTerminal()) {
            return 0;
        }

        return $this->savepoints->count() + 1;
    }

    public function state(): TransactionState
    {
        return $this->state;
    }

    public function isActive(): bool
    {
        return $this->state->isActive();
    }

    public function rollbackOnly(): bool
    {
        return $this->state === TransactionState::MarkedRollbackOnly;
    }

    /**
     * Derive the observable outcome from the current state.
     */
    public function outcome(): TransactionOutcome
    {
        return match ($this->state) {
            TransactionState::Committed => TransactionOutcome::Committed,
            TransactionState::RolledBack, TransactionState::Failed => TransactionOutcome::RolledBack,
            TransactionState::Unknown => TransactionOutcome::Unknown,
            default => TransactionOutcome::NotStarted,
        };
    }

    /**
     * Whether the configured timeout (if any) has been exceeded.
     */
    public function isTimedOut(): bool
    {
        $timeout = $this->definition->timeout;

        if ($timeout === null) {
            return false;
        }

        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $this->startedAt->getTimestamp();

        return $elapsed > $timeout;
    }

    // ── Savepoint stack ────────────────────────────────────────────────

    public function beginNested(string $savepointName): SavepointId
    {
        $savepoint = new SavepointId($savepointName);
        $this->savepoints->push($savepoint);

        return $savepoint;
    }

    public function releaseNested(): SavepointId
    {
        try {
            return $this->savepoints->pop();
        } catch (SavepointException $exception) {
            throw new SavepointException('No nested transaction savepoint is available to release.');
        }
    }

    public function rollbackNested(): SavepointId
    {
        try {
            return $this->savepoints->pop();
        } catch (SavepointException $exception) {
            throw new SavepointException('No nested transaction savepoint is available to rollback.');
        }
    }

    /**
     * @return list<SavepointId>
     */
    public function savepoints(): array
    {
        return $this->savepoints->all();
    }

    // ── State transitions ──────────────────────────────────────────────

    public function markStarting(): void
    {
        $this->state = TransactionState::Starting;
    }

    public function markActive(): void
    {
        $this->state = TransactionState::Active;
    }

    public function markRollbackOnly(): void
    {
        if ($this->state->isTerminal()) {
            throw new SavepointException('Cannot mark a completed transaction as rollback-only.');
        }

        $this->state = TransactionState::MarkedRollbackOnly;
    }

    public function markCommitting(): void
    {
        $this->state = TransactionState::Committing;
        $this->commitDispatchState = CommitDispatchState::Dispatching;
    }

    public function markCommitDispatched(): void
    {
        $this->commitDispatchState = CommitDispatchState::Dispatched;
    }

    public function markCommitted(): void
    {
        $this->state = TransactionState::Committed;
        $this->commitDispatchState = CommitDispatchState::Confirmed;
        $this->savepoints->clear();
    }

    public function markRollingBack(): void
    {
        $this->state = TransactionState::RollingBack;
    }

    public function markRolledBack(): void
    {
        $this->state = TransactionState::RolledBack;
        $this->savepoints->clear();
    }

    public function markFailed(): void
    {
        $this->state = TransactionState::Failed;
        $this->savepoints->clear();
    }

    /**
     * Mark the transaction as Unknown: commit was dispatched but confirmation
     * was lost. This is terminal.
     */
    public function markUnknown(): void
    {
        $this->state = TransactionState::Unknown;
        $this->savepoints->clear();
    }

    /**
     * Guard for operations that are illegal once the outcome is Unknown.
     */
    public function assertNotUnknown(): void
    {
        if ($this->state === TransactionState::Unknown) {
            throw new TransactionOutcomeUnknownException(sprintf(
                'Transaction [%s] outcome is unknown and cannot be committed or rolled back.',
                $this->id->value,
            ));
        }
    }

    // ── Legacy completion helpers (kept for compatibility) ─────────────

    public function completeCommitted(): void
    {
        $this->markCommitted();
    }

    public function completeRolledBack(): void
    {
        $this->markRolledBack();
    }
}
