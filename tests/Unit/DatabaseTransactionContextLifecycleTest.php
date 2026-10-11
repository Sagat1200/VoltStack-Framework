<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Transaction\CommitDispatchState;
use Quantum\Database\Transaction\Exception\SavepointException;
use Quantum\Database\Transaction\Exception\TransactionOutcomeUnknownException;
use Quantum\Database\Transaction\TransactionContext;
use Quantum\Database\Transaction\TransactionId;
use Quantum\Database\Transaction\TransactionOutcome;
use Quantum\Database\Transaction\TransactionState;

final class DatabaseTransactionContextLifecycleTest extends TestCase
{
    private function context(): TransactionContext
    {
        return new TransactionContext(new TransactionId('tx-lifecycle'), 'default');
    }

    public function test_initial_state_is_active(): void
    {
        $context = $this->context();

        self::assertSame(TransactionState::Active, $context->state());
        self::assertTrue($context->isActive());
        self::assertSame(TransactionOutcome::NotStarted, $context->outcome());
        self::assertSame(CommitDispatchState::NotDispatched, $context->commitDispatchState());
        self::assertSame(1, $context->depth());
    }

    public function test_mark_starting_then_active(): void
    {
        $context = $this->context();

        $context->markStarting();
        self::assertSame(TransactionState::Starting, $context->state());

        $context->markActive();
        self::assertSame(TransactionState::Active, $context->state());
        self::assertTrue($context->isActive());
    }

    public function test_rollback_only_transition(): void
    {
        $context = $this->context();

        $context->markRollbackOnly();

        self::assertSame(TransactionState::MarkedRollbackOnly, $context->state());
        self::assertTrue($context->rollbackOnly());
        self::assertTrue($context->isActive());
    }

    public function test_mark_rollback_only_on_terminal_throws(): void
    {
        $context = $this->context();
        $context->markCommitted();

        $this->expectException(SavepointException::class);

        $context->markRollbackOnly();
    }

    public function test_commit_transitions_and_outcome(): void
    {
        $context = $this->context();

        $context->markCommitting();
        self::assertSame(TransactionState::Committing, $context->state());
        self::assertSame(CommitDispatchState::Dispatching, $context->commitDispatchState());

        $context->markCommitDispatched();
        self::assertSame(CommitDispatchState::Dispatched, $context->commitDispatchState());

        $context->markCommitted();
        self::assertSame(TransactionState::Committed, $context->state());
        self::assertSame(CommitDispatchState::Confirmed, $context->commitDispatchState());
        self::assertSame(TransactionOutcome::Committed, $context->outcome());
        self::assertSame(0, $context->depth());
    }

    public function test_rollback_transitions_and_outcome(): void
    {
        $context = $this->context();

        $context->markRollingBack();
        self::assertSame(TransactionState::RollingBack, $context->state());

        $context->markRolledBack();
        self::assertSame(TransactionState::RolledBack, $context->state());
        self::assertSame(TransactionOutcome::RolledBack, $context->outcome());
        self::assertSame(0, $context->depth());
    }

    public function test_failed_state_outcome_is_rolled_back(): void
    {
        $context = $this->context();

        $context->markFailed();

        self::assertSame(TransactionState::Failed, $context->state());
        self::assertSame(TransactionOutcome::RolledBack, $context->outcome());
    }

    public function test_unknown_outcome_is_terminal(): void
    {
        $context = $this->context();

        $context->markUnknown();

        self::assertSame(TransactionState::Unknown, $context->state());
        self::assertSame(TransactionOutcome::Unknown, $context->outcome());
        self::assertSame(0, $context->depth());
    }

    public function test_assert_not_unknown_passes_when_active(): void
    {
        $context = $this->context();

        // Should not throw.
        $context->assertNotUnknown();

        self::assertTrue(true);
    }

    public function test_assert_not_unknown_throws_when_unknown(): void
    {
        $context = $this->context();
        $context->markUnknown();

        $this->expectException(TransactionOutcomeUnknownException::class);

        $context->assertNotUnknown();
    }

    public function test_savepoint_depth_tracking(): void
    {
        $context = $this->context();

        self::assertSame(1, $context->depth());

        $sp1 = $context->beginNested('sp_1');
        self::assertSame('sp_1', $sp1->value);
        self::assertSame(2, $context->depth());

        $sp2 = $context->beginNested('sp_2');
        self::assertSame(3, $context->depth());

        $released = $context->releaseNested();
        self::assertSame($sp2, $released);
        self::assertSame(2, $context->depth());

        $rolledBack = $context->rollbackNested();
        self::assertSame($sp1, $rolledBack);
        self::assertSame(1, $context->depth());
    }

    public function test_rollback_nested_on_empty_throws(): void
    {
        $context = $this->context();

        $this->expectException(SavepointException::class);
        $this->expectExceptionMessage('No nested transaction savepoint is available to rollback.');

        $context->rollbackNested();
    }

    public function test_commit_dispatch_state_starts_not_dispatched(): void
    {
        $context = $this->context();

        self::assertSame(CommitDispatchState::NotDispatched, $context->commitDispatchState());
    }
}
