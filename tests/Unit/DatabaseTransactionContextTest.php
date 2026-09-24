<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Transaction\TransactionContext;
use Quantum\Database\Transaction\TransactionException;
use Quantum\Database\Transaction\TransactionId;
use Quantum\Database\Transaction\TransactionState;

final class DatabaseTransactionContextTest extends TestCase
{
    public function test_it_tracks_nested_depth_and_state_transitions(): void
    {
        $context = new TransactionContext(new TransactionId('tx-test'), 'default');

        self::assertSame('tx-test', $context->id()->value);
        self::assertSame('default', $context->connectionName());
        self::assertSame(1, $context->depth());
        self::assertSame(TransactionState::Active, $context->state());
        self::assertTrue($context->isActive());

        $context->beginNested('sp_1');

        self::assertSame(2, $context->depth());
        self::assertSame('sp_1', $context->releaseNested());
        self::assertSame(1, $context->depth());

        $context->markRollbackOnly();

        self::assertTrue($context->rollbackOnly());
        self::assertSame(TransactionState::RollbackOnly, $context->state());

        $context->completeRolledBack();

        self::assertSame(0, $context->depth());
        self::assertSame(TransactionState::RolledBack, $context->state());
        self::assertFalse($context->isActive());
    }

    public function test_it_rejects_missing_nested_savepoints(): void
    {
        $context = new TransactionContext(new TransactionId('tx-test'), 'default');

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('No nested transaction savepoint is available to release.');

        $context->releaseNested();
    }
}
