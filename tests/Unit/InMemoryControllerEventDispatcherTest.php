<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Quantum\Controllers\Observability\Engine\InMemoryControllerEventDispatcher;
use Quantum\Controllers\Observability\Events\ControllerEvent;

final class InMemoryControllerEventDispatcherTest extends TestCase
{
    public function test_it_keeps_a_bounded_ring_buffer(): void
    {
        $dispatcher = new InMemoryControllerEventDispatcher(maxEvents: 2);

        $dispatcher->dispatch(new ControllerEvent(
            name: 'a',
            version: 1,
            executionId: 'x',
            occurredAt: new DateTimeImmutable(),
            sequence: 1,
        ));
        $dispatcher->dispatch(new ControllerEvent(
            name: 'b',
            version: 1,
            executionId: 'x',
            occurredAt: new DateTimeImmutable(),
            sequence: 2,
        ));
        $dispatcher->dispatch(new ControllerEvent(
            name: 'c',
            version: 1,
            executionId: 'x',
            occurredAt: new DateTimeImmutable(),
            sequence: 3,
        ));

        $events = $dispatcher->events();

        self::assertCount(2, $events);
        self::assertSame('b', $events[0]->name());
        self::assertSame('c', $events[1]->name());
        self::assertSame('c', $dispatcher->last()?->name());
    }
}