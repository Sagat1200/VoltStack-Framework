<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Transaction\SavepointId;
use Quantum\Database\Transaction\SavepointStack;
use Quantum\Database\Transaction\Exception\SavepointException;

final class DatabaseSavepointStackTest extends TestCase
{
    public function test_generate_produces_deterministic_name(): void
    {
        $id = SavepointId::generate('tx-0123456789abcdef', 2);

        // substr('tx-0123456789abcdef', 0, 12) === 'tx-012345678'
        self::assertSame('quantum_sp_tx-012345678_2', $id->value);
        self::assertSame('quantum_sp_tx-012345678_2', (string) $id);
    }

    public function test_generate_truncates_long_transaction_id(): void
    {
        $id = SavepointId::generate(str_repeat('a', 40), 1);

        self::assertSame('quantum_sp_aaaaaaaaaaaa_1', $id->value);
    }

    public function test_stack_is_empty_by_default(): void
    {
        $stack = new SavepointStack();

        self::assertTrue($stack->isEmpty());
        self::assertSame(0, $stack->count());
        self::assertNull($stack->peek());
    }

    public function test_push_and_pop_lifo_order(): void
    {
        $stack = new SavepointStack();
        $first = new SavepointId('sp_1');
        $second = new SavepointId('sp_2');

        $stack->push($first);
        $stack->push($second);

        self::assertSame(2, $stack->count());
        self::assertSame($second, $stack->peek());

        self::assertSame($second, $stack->pop());
        self::assertSame($first, $stack->pop());
        self::assertTrue($stack->isEmpty());
    }

    public function test_pop_on_empty_throws(): void
    {
        $stack = new SavepointStack();

        $this->expectException(SavepointException::class);

        $stack->pop();
    }

    public function test_clear_resets_stack(): void
    {
        $stack = new SavepointStack();
        $stack->push(new SavepointId('sp_1'));
        $stack->push(new SavepointId('sp_2'));

        $stack->clear();

        self::assertTrue($stack->isEmpty());
        self::assertSame([], $stack->all());
    }

    public function test_all_returns_ordered_list(): void
    {
        $stack = new SavepointStack();
        $first = new SavepointId('sp_1');
        $second = new SavepointId('sp_2');

        $stack->push($first);
        $stack->push($second);

        self::assertSame([$first, $second], $stack->all());
    }
}
