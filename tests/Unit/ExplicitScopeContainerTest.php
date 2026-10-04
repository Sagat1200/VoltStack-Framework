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

    public function test_explicit_unit_entry_points_classify_request_job_and_command_scopes(): void
    {
        $container = new Container();

        $container->enterRequestScope();
        self::assertSame('request', $container->currentScopeKind());
        $container->leaveScope();

        $container->enterJobScope();
        self::assertSame('job', $container->currentScopeKind());
        $container->leaveScope();

        $container->enterCommandScope();
        self::assertSame('command', $container->currentScopeKind());
    }

    public function test_worker_scope_can_only_be_entered_from_root(): void
    {
        $container = new Container();

        $container->enterWorkerScope();
        self::assertSame('worker', $container->currentScopeKind());

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Cannot enter [worker] scope from [worker] scope.');

        $container->enterWorkerScope();
    }

    public function test_request_scope_cannot_be_entered_from_another_unit_scope(): void
    {
        $container = new Container();
        $container->enterRequestScope();

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Cannot enter [request] scope from [request] scope.');

        $container->enterRequestScope();
    }

    public function test_tenant_scope_requires_a_parent_unit_scope(): void
    {
        $container = new Container();

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Cannot enter [tenant] scope from [root] scope.');

        $container->enterTenantScope();
    }

    public function test_tenant_scope_can_be_entered_from_request_job_and_command_scopes_only(): void
    {
        $requestContainer = new Container();
        $requestContainer->enterRequestScope();
        $requestContainer->enterTenantScope();
        self::assertSame('tenant', $requestContainer->currentScopeKind());

        $jobContainer = new Container();
        $jobContainer->enterJobScope();
        $jobContainer->enterTenantScope();
        self::assertSame('tenant', $jobContainer->currentScopeKind());

        $commandContainer = new Container();
        $commandContainer->enterCommandScope();
        $commandContainer->enterTenantScope();
        self::assertSame('tenant', $commandContainer->currentScopeKind());
    }

    public function test_tenant_scope_cannot_be_nested_under_another_tenant_scope(): void
    {
        $container = new Container();
        $container->enterRequestScope();
        $container->enterTenantScope();

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Cannot enter [tenant] scope from [tenant] scope.');

        $container->enterTenantScope();
    }

    public function test_scope_execution_helpers_return_callback_results_and_restore_previous_scope(): void
    {
        $container = new Container();

        $result = $container->runInRequestScope(function (Container $container): array {
            $requestKind = $container->currentScopeKind();

            $tenantKind = $container->runInTenantScope(
                fn(Container $container): string => $container->currentScopeKind()
            );

            return [$requestKind, $tenantKind, $container->currentScopeKind()];
        });

        self::assertSame(['request', 'tenant', 'request'], $result);
        self::assertSame('root', $container->currentScopeKind());
    }

    public function test_scope_execution_helpers_close_the_opened_scope_even_when_the_callback_fails(): void
    {
        $container = new Container();

        try {
            $container->runInJobScope(function (): never {
                throw new \RuntimeException('boom');
            });
            self::fail('The callback exception should be propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame('root', $container->currentScopeKind());
        self::assertFalse($container->hasActiveScope());
    }

    public function test_scope_execution_helpers_support_worker_command_and_tenant_chains(): void
    {
        $container = new Container();

        $kinds = $container->runInWorkerScope(function (Container $container): array {
            $workerKind = $container->currentScopeKind();

            $commandKinds = $container->runInCommandScope(function (Container $container): array {
                return [
                    $container->currentScopeKind(),
                    $container->runInTenantScope(
                        fn(Container $container): string => $container->currentScopeKind()
                    ),
                    $container->currentScopeKind(),
                ];
            });

            return [$workerKind, ...$commandKinds, $container->currentScopeKind()];
        });

        self::assertSame(['worker', 'command', 'tenant', 'command', 'worker'], $kinds);
        self::assertSame('root', $container->currentScopeKind());
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

    public function test_tenant_scoped_bindings_can_retain_job_scoped_dependencies_when_job_is_the_parent_unit(): void
    {
        $container = new Container();

        $container->scopedFor('job.service', static fn(): object => new \stdClass(), 'job');
        $container->scopedFor('tenant.service', function (Container $container): object {
            return (object) ['dependency' => $container->make('job.service')];
        }, 'tenant');

        $container->enterScope('job');
        $container->enterScope('tenant');

        $resolved = $container->make('tenant.service');

        self::assertInstanceOf(\stdClass::class, $resolved->dependency);
    }

    public function test_tenant_scoped_bindings_can_retain_command_scoped_dependencies_when_command_is_the_parent_unit(): void
    {
        $container = new Container();

        $container->scopedFor('command.service', static fn(): object => new \stdClass(), 'command');
        $container->scopedFor('tenant.service', function (Container $container): object {
            return (object) ['dependency' => $container->make('command.service')];
        }, 'tenant');

        $container->enterScope('command');
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

    public function test_job_scoped_bindings_cannot_retain_tenant_scoped_dependencies(): void
    {
        $container = new Container();

        $container->scopedFor('tenant.service', static fn(): object => new \stdClass(), 'tenant');
        $container->scopedFor('job.service', function (Container $container): object {
            return (object) ['dependency' => $container->make('tenant.service')];
        }, 'job');

        $container->enterScope('job');
        $container->enterScope('tenant');

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('cannot retain dependency [tenant.service] with scope [tenant]');

        $container->make('job.service');
    }

    public function test_worker_scoped_bindings_fall_back_to_the_root_owner_frame_when_no_worker_scope_is_active(): void
    {
        $container = new Container();
        $sequence = 0;

        $container->scopedFor('worker.service', function () use (&$sequence): object {
            return (object) ['id' => ++$sequence];
        }, 'worker');

        $rootOwned = $container->make('worker.service');

        $container->enterScope('request');
        self::assertSame($rootOwned, $container->make('worker.service'));

        $container->enterScope('tenant');
        self::assertSame($rootOwned, $container->make('worker.service'));
    }

    public function test_singleton_bindings_cannot_retain_generic_scoped_dependencies(): void
    {
        $container = new Container();

        $container->scoped('scoped.service', static fn(): object => new \stdClass());
        $container->singleton('singleton.service', function (Container $container): object {
            return (object) ['dependency' => $container->make('scoped.service')];
        });

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Singleton binding [singleton.service] cannot retain scoped dependency [scoped.service]');

        $container->make('singleton.service');
    }

    public function test_singleton_bindings_cannot_retain_request_scoped_dependencies(): void
    {
        $container = new Container();

        $container->scopedFor('request.service', static fn(): object => new \stdClass(), 'request');
        $container->singleton('singleton.service', function (Container $container): object {
            return (object) ['dependency' => $container->make('request.service')];
        });

        $container->enterScope('request');

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Singleton binding [singleton.service] cannot retain scoped dependency [request.service] with scope [request]');

        $container->make('singleton.service');
    }

    public function test_singleton_bindings_cannot_indirectly_retain_request_scoped_dependencies_through_transients(): void
    {
        $container = new Container();

        $container->scopedFor('request.service', static fn(): object => new \stdClass(), 'request');
        $container->bind('transient.helper', function (Container $container): object {
            return (object) ['dependency' => $container->make('request.service')];
        });
        $container->singleton('singleton.service', function (Container $container): object {
            return (object) ['helper' => $container->make('transient.helper')];
        });

        $container->enterScope('request');

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Singleton binding [singleton.service] cannot retain scoped dependency [request.service] with scope [request]');

        $container->make('singleton.service');
    }

    public function test_generic_scoped_bindings_cannot_indirectly_retain_request_dependencies_inside_generic_frames(): void
    {
        $container = new Container();

        $container->scopedFor('request.service', static fn(): object => new \stdClass(), 'request');
        $container->bind('transient.helper', function (Container $container): object {
            return (object) ['dependency' => $container->make('request.service')];
        });
        $container->scoped('generic.service', function (Container $container): object {
            return (object) ['helper' => $container->make('transient.helper')];
        });

        $container->enterScope('request');
        $container->enterScope('custom-segment');

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Scoped binding [generic.service] with scope [scope] cannot retain dependency [request.service] with scope [request]');

        $container->make('generic.service');
    }
}
