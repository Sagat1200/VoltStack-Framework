<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

use Throwable;

/**
 * Registry of lifecycle callbacks attached to a TransactionContext.
 *
 * Callbacks are synchronous and run inside the PHP process. They observe
 * lifecycle transitions; they never invent transactional atomicity over
 * external resources.
 *
 * Semantics:
 *  - beforeCommit: runs before COMMIT dispatch; a thrown exception marks the
 *    transaction rollback-only and triggers a rollback.
 *  - afterCommit: runs only when the outcome is confirmed Committed.
 *  - afterRollback: runs only when the outcome is confirmed RolledBack.
 *  - afterCompletion: always runs on terminal outcome (Committed, RolledBack,
 *    Unknown), receiving the TransactionOutcome as second argument.
 *
 * Failures in afterCommit/afterRollback/afterCompletion do not alter an already
 * confirmed outcome; they are reported via telemetry.
 */
final class CallbackRegistry
{
    /**
     * @var list<callable(TransactionContext): void>
     */
    private array $beforeCommit = [];

    /**
     * @var list<callable(TransactionContext): void>
     */
    private array $afterCommit = [];

    /**
     * @var list<callable(TransactionContext): void>
     */
    private array $afterRollback = [];

    /**
     * @var list<callable(TransactionContext, TransactionOutcome): void>
     */
    private array $afterCompletion = [];

    /**
     * @param callable(TransactionContext): void $callback
     */
    public function addBeforeCommit(callable $callback): void
    {
        $this->beforeCommit[] = $callback;
    }

    /**
     * @param callable(TransactionContext): void $callback
     */
    public function addAfterCommit(callable $callback): void
    {
        $this->afterCommit[] = $callback;
    }

    /**
     * @param callable(TransactionContext): void $callback
     */
    public function addAfterRollback(callable $callback): void
    {
        $this->afterRollback[] = $callback;
    }

    /**
     * @param callable(TransactionContext, TransactionOutcome): void $callback
     */
    public function addAfterCompletion(callable $callback): void
    {
        $this->afterCompletion[] = $callback;
    }

    /**
     * @throws Throwable when a beforeCommit callback throws; the caller is
     *                   responsible for marking the transaction rollback-only.
     */
    public function dispatchBeforeCommit(TransactionContext $context): void
    {
        foreach ($this->beforeCommit as $callback) {
            $callback($context);
        }
    }

    public function dispatchAfterCommit(TransactionContext $context): void
    {
        foreach ($this->afterCommit as $callback) {
            $callback($context);
        }
    }

    public function dispatchAfterRollback(TransactionContext $context): void
    {
        foreach ($this->afterRollback as $callback) {
            $callback($context);
        }
    }

    public function dispatchAfterCompletion(TransactionContext $context, TransactionOutcome $outcome): void
    {
        foreach ($this->afterCompletion as $callback) {
            $callback($context, $outcome);
        }
    }
}
