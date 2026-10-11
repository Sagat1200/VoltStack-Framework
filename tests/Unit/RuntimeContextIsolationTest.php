<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Http\Request;
use VoltStack\Runtime\Context\RuntimeContext;

final class RuntimeContextIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RuntimeContext::setCurrent(null);
    }

    protected function tearDown(): void
    {
        RuntimeContext::setCurrent(null);

        parent::tearDown();
    }

    public function test_current_returns_null_when_no_context_is_active(): void
    {
        self::assertNull(RuntimeContext::current());
        self::assertFalse(RuntimeContext::has());
    }

    public function test_activate_makes_context_current_and_deactivate_restores_previous(): void
    {
        $outer = new RuntimeContext('outer', Request::create('/outer'), microtime(true));
        $inner = new RuntimeContext('inner', Request::create('/inner'), microtime(true));

        $outerSlot = RuntimeContext::activate($outer);
        self::assertTrue(RuntimeContext::has());
        self::assertSame('outer', RuntimeContext::current()?->requestId());

        $innerSlot = RuntimeContext::activate($inner);
        self::assertSame('inner', RuntimeContext::current()?->requestId());

        RuntimeContext::deactivate($innerSlot);
        self::assertSame('outer', RuntimeContext::current()?->requestId());

        RuntimeContext::deactivate($outerSlot);
        self::assertNull(RuntimeContext::current());
        self::assertFalse(RuntimeContext::has());
    }

    public function test_deactivate_unknown_slot_is_a_no_op(): void
    {
        $context = new RuntimeContext('stable', Request::create('/stable'), microtime(true));
        $slot = RuntimeContext::activate($context);

        RuntimeContext::deactivate('does-not-exist');

        self::assertSame('stable', RuntimeContext::current()?->requestId());

        RuntimeContext::deactivate($slot);
        self::assertNull(RuntimeContext::current());
    }

    public function test_deactivate_null_slot_is_a_no_op(): void
    {
        $context = new RuntimeContext('stable', Request::create('/stable'), microtime(true));
        $slot = RuntimeContext::activate($context);

        RuntimeContext::deactivate(null);

        self::assertSame('stable', RuntimeContext::current()?->requestId());

        RuntimeContext::deactivate($slot);
        self::assertNull(RuntimeContext::current());
    }

    public function test_set_current_null_clears_everything_even_with_pending_slots(): void
    {
        $context = new RuntimeContext('pending', Request::create('/pending'), microtime(true));
        RuntimeContext::activate($context);
        self::assertNotNull(RuntimeContext::current());

        RuntimeContext::setCurrent(null);

        self::assertNull(RuntimeContext::current());
        self::assertFalse(RuntimeContext::has());
    }

    public function test_set_current_with_context_makes_it_current_without_leaking_previous_stack(): void
    {
        $previous = new RuntimeContext('previous', Request::create('/previous'), microtime(true));
        RuntimeContext::activate($previous);

        $replacement = new RuntimeContext('replacement', Request::create('/replacement'), microtime(true));
        RuntimeContext::setCurrent($replacement);

        self::assertSame('replacement', RuntimeContext::current()?->requestId());
        // Al desactivar el slot activo (legacy), current vuelve a null porque setCurrent limpió el stack anterior.
        RuntimeContext::setCurrent(null);
        self::assertNull(RuntimeContext::current());
    }

    public function test_activate_after_set_current_replaces_current_without_leaking_legacy_context(): void
    {
        $legacy = new RuntimeContext('legacy', Request::create('/legacy'), microtime(true));
        RuntimeContext::setCurrent($legacy);
        self::assertSame('legacy', RuntimeContext::current()?->requestId());

        $fresh = new RuntimeContext('fresh', Request::create('/fresh'), microtime(true));
        $freshSlot = RuntimeContext::activate($fresh);

        self::assertSame('fresh', RuntimeContext::current()?->requestId());

        RuntimeContext::deactivate($freshSlot);
        // setCurrent deja el contexto legacy como slot del stack; al desactivar
        // fresh, current() vuelve al contexto inyectado por setCurrent.
        self::assertSame('legacy', RuntimeContext::current()?->requestId());

        // Un reset final limpia todo sin huérfanos.
        RuntimeContext::setCurrent(null);
        self::assertNull(RuntimeContext::current());
    }

    public function test_current_does_not_return_a_removed_context_after_any_deactivate_order(): void
    {
        $a = new RuntimeContext('a', Request::create('/a'), microtime(true));
        $b = new RuntimeContext('b', Request::create('/b'), microtime(true));

        $slotA = RuntimeContext::activate($a);
        $slotB = RuntimeContext::activate($b);

        // Desactivar en orden inverso al stack: primero A (interior del stack).
        RuntimeContext::deactivate($slotA);
        self::assertSame('b', RuntimeContext::current()?->requestId());

        RuntimeContext::deactivate($slotB);
        self::assertNull(RuntimeContext::current());
    }
}
