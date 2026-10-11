<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Transaction\CallbackRegistry;
use Quantum\Database\Transaction\TransactionContext;
use Quantum\Database\Transaction\TransactionId;
use Quantum\Database\Transaction\TransactionOutcome;
use RuntimeException;

final class DatabaseCallbackRegistryTest extends TestCase
{
    private function context(): TransactionContext
    {
        return new TransactionContext(new TransactionId('tx-cb'), 'default');
    }

    public function test_before_commit_callbacks_run_in_registration_order(): void
    {
        $registry = new CallbackRegistry();
        $order = [];

        $registry->addBeforeCommit(function () use (&$order): void {
            $order[] = 'first';
        });
        $registry->addBeforeCommit(function () use (&$order): void {
            $order[] = 'second';
        });

        $registry->dispatchBeforeCommit($this->context());

        self::assertSame(['first', 'second'], $order);
    }

    public function test_after_commit_callbacks_run_on_commit(): void
    {
        $registry = new CallbackRegistry();
        $fired = false;

        $registry->addAfterCommit(function () use (&$fired): void {
            $fired = true;
        });

        $registry->dispatchAfterCommit($this->context());

        self::assertTrue($fired);
    }

    public function test_after_rollback_callbacks_run_on_rollback(): void
    {
        $registry = new CallbackRegistry();
        $fired = false;

        $registry->addAfterRollback(function () use (&$fired): void {
            $fired = true;
        });

        $registry->dispatchAfterRollback($this->context());

        self::assertTrue($fired);
    }

    public function test_after_completion_receives_outcome(): void
    {
        $registry = new CallbackRegistry();
        $received = null;

        $registry->addAfterCompletion(function ($context, TransactionOutcome $outcome) use (&$received): void {
            $received = $outcome;
        });

        $registry->dispatchAfterCompletion($this->context(), TransactionOutcome::Committed);

        self::assertSame(TransactionOutcome::Committed, $received);
    }

    public function test_before_commit_exception_propagates(): void
    {
        $registry = new CallbackRegistry();

        $registry->addBeforeCommit(function (): void {
            throw new RuntimeException('boom');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $registry->dispatchBeforeCommit($this->context());
    }

    public function test_after_completion_runs_for_all_terminal_outcomes(): void
    {
        $registry = new CallbackRegistry();
        $outcomes = [];

        $registry->addAfterCompletion(function ($context, TransactionOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        });

        $ctx = $this->context();
        $registry->dispatchAfterCompletion($ctx, TransactionOutcome::Committed);
        $registry->dispatchAfterCompletion($ctx, TransactionOutcome::RolledBack);
        $registry->dispatchAfterCompletion($ctx, TransactionOutcome::Unknown);

        self::assertSame([
            TransactionOutcome::Committed,
            TransactionOutcome::RolledBack,
            TransactionOutcome::Unknown,
        ], $outcomes);
    }
}
