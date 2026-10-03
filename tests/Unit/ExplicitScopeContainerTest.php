<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Container\Container;

final class ExplicitScopeContainerTest extends TestCase
{
    public function test_explicit_scopes_isolate_scoped_instances_and_restore_the_parent_scope(): void
    {
        $container = new Container();
        $sequence = 0;

        $container->scoped('scoped.service', function () use (&$sequence): object {
            return (object) ['id' => ++$sequence];
        });

        $root = $container->make('scoped.service');

        self::assertFalse($container->hasActiveScope());

        $container->enterScope('request');
        $child = $container->make('scoped.service');

        self::assertTrue($container->hasActiveScope());
        self::assertNotSame($root, $child);
        self::assertSame(2, $child->id);

        $container->leaveScope();

        self::assertFalse($container->hasActiveScope());
        self::assertSame($root, $container->make('scoped.service'));
    }

    public function test_flushing_the_current_explicit_scope_does_not_flush_the_parent_scope(): void
    {
        $container = new Container();
        $sequence = 0;

        $container->scoped('scoped.service', function () use (&$sequence): object {
            return (object) ['id' => ++$sequence];
        });

        $root = $container->make('scoped.service');

        $container->enterScope('request');
        $firstChild = $container->make('scoped.service');

        $container->flushScope();
        $secondChild = $container->make('scoped.service');

        self::assertNotSame($firstChild, $secondChild);
        self::assertSame(3, $secondChild->id);

        $container->leaveScope();

        self::assertSame($root, $container->make('scoped.service'));
    }
}
