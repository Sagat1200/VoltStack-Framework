<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Container\Container;
use Quantum\Container\Exceptions\BindingResolutionException;

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

    public function test_request_scoped_bindings_are_reused_inside_nested_tenant_scopes(): void
    {
        $container = new Container();
        $sequence = 0;

        $container->scopedFor('request.service', function () use (&$sequence): object {
            return (object) ['id' => ++$sequence];
        }, 'request');

        $container->enterScope('request');
        $requestService = $container->make('request.service');

        $container->enterScope('tenant');
        $tenantService = $container->make('request.service');

        self::assertSame($requestService, $tenantService);
        self::assertSame(1, $tenantService->id);

        $container->leaveScope();
        self::assertSame($requestService, $container->make('request.service'));

        $container->leaveScope();
    }

    public function test_request_scoped_bindings_require_an_active_request_scope(): void
    {
        $container = new Container();
        $container->scopedFor('request.service', static fn(): object => new \stdClass(), 'request');

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('requires an active [request] scope');

        $container->make('request.service');
    }

    public function test_tenant_scoped_bindings_can_retain_request_scoped_dependencies(): void
    {
        $container = new Container();

        $container->scopedFor('request.service', static fn(): object => new \stdClass(), 'request');
        $container->scopedFor('tenant.service', function (Container $container): object {
            return (object) ['dependency' => $container->make('request.service')];
        }, 'tenant');

        $container->enterScope('request');
        $container->enterScope('tenant');

        $resolved = $container->make('tenant.service');

        self::assertInstanceOf(\stdClass::class, $resolved->dependency);
    }

    public function test_request_scoped_bindings_cannot_retain_tenant_scoped_dependencies(): void
    {
        $container = new Container();

        $container->scopedFor('tenant.service', static fn(): object => new \stdClass(), 'tenant');
        $container->scopedFor('request.service', function (Container $container): object {
            return (object) ['dependency' => $container->make('tenant.service')];
        }, 'request');

        $container->enterScope('request');
        $container->enterScope('tenant');

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('cannot retain dependency [tenant.service] with scope [tenant]');

        $container->make('request.service');
    }

    public function test_worker_scoped_bindings_cannot_retain_request_scoped_dependencies(): void
    {
        $container = new Container();

        $container->enterScope('worker');
        $container->enterScope('request');

        $container->scopedFor('request.service', static fn(): object => new \stdClass(), 'request');
        $container->scopedFor('worker.service', function (Container $container): object {
            return (object) ['dependency' => $container->make('request.service')];
        }, 'worker');

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('cannot retain dependency [request.service] with scope [request]');

        $container->make('worker.service');
    }
}
