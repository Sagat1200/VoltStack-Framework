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

    public function test_scope_metadata_tracks_kind_depth_and_parent_relationships(): void
    {
        $container = new Container();

        self::assertSame('root', $container->currentScopeKind());
        self::assertSame('root', $container->currentScopeName());
        self::assertSame(0, $container->currentScopeDepth());
        self::assertNull($container->currentScopeParentId());

        $rootId = $container->currentScopeId();

        $container->enterScope('request');

        self::assertSame('request', $container->currentScopeKind());
        self::assertSame('request', $container->currentScopeName());
        self::assertSame(1, $container->currentScopeDepth());
        self::assertSame($rootId, $container->currentScopeParentId());

        $requestId = $container->currentScopeId();
        $container->enterScope('tenant');

        self::assertSame('tenant', $container->currentScopeKind());
        self::assertSame('tenant', $container->currentScopeName());
        self::assertSame(2, $container->currentScopeDepth());
        self::assertSame($requestId, $container->currentScopeParentId());

        $container->leaveScope();
        self::assertSame('request', $container->currentScopeKind());

        $container->leaveScope();
        self::assertSame('root', $container->currentScopeKind());
        self::assertSame($rootId, $container->currentScopeId());
    }

    public function test_unknown_scope_names_are_classified_as_generic_scope_kind(): void
    {
        $container = new Container();

        $container->enterScope('custom-segment');

        self::assertSame('scope', $container->currentScopeKind());
        self::assertSame('custom-segment', $container->currentScopeName());
    }
}
