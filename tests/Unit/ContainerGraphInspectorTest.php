<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Container\Container;
use Quantum\Container\Graph\ContainerGraphInspector;

final class ContainerGraphInspectorTest extends TestCase
{
    public function test_it_builds_a_static_snapshot_of_registered_bindings_and_aliases(): void
    {
        $container = new Container();
        $container->singleton(GraphConsumer::class, GraphConsumer::class);
        $container->scopedFor(GraphDependency::class, GraphDependency::class, 'request');
        $container->bind('graph.closure', static fn(): object => new \stdClass());
        $container->alias(GraphConsumer::class, 'graph.consumer');

        $graph = (new ContainerGraphInspector())->inspect($container);

        $consumer = $graph->service(GraphConsumer::class);
        self::assertNotNull($consumer);
        self::assertSame('singleton', $consumer->lifetime);
        self::assertSame('class', $consumer->concreteKind);
        self::assertSame(GraphConsumer::class, $consumer->concreteName);
        self::assertTrue($consumer->analyzable);
        self::assertCount(1, $consumer->dependencies);
        self::assertSame(GraphDependency::class, $consumer->dependencies[0]->abstract);
        self::assertFalse($consumer->dependencies[0]->optional);
        self::assertCount(1, $consumer->parameters);
        self::assertSame('label', $consumer->parameters[0]->name);
        self::assertSame('string', $consumer->parameters[0]->type);
        self::assertTrue($consumer->parameters[0]->builtin);
        self::assertTrue($consumer->parameters[0]->hasDefault);

        $dependency = $graph->service(GraphDependency::class);
        self::assertNotNull($dependency);
        self::assertSame('scoped', $dependency->lifetime);
        self::assertSame('request', $dependency->scopeKind);
        self::assertTrue($dependency->analyzable);

        $closure = $graph->service('graph.closure');
        self::assertNotNull($closure);
        self::assertSame('closure', $closure->concreteKind);
        self::assertFalse($closure->analyzable);
        self::assertSame([], $closure->dependencies);
        self::assertSame([], $closure->parameters);

        self::assertSame([
            'graph.consumer' => GraphConsumer::class,
        ], $graph->aliases());
    }

    public function test_it_reports_missing_targets_non_instantiable_targets_and_required_scalar_parameters(): void
    {
        $container = new Container();
        $container->bind('graph.missing', 'Vendor\\Missing\\Service');
        $container->bind(GraphNeedsScalar::class, GraphNeedsScalar::class);
        $container->bind(GraphAbstractTarget::class, GraphAbstractTarget::class);
        $container->bind(GraphNeedsContract::class, GraphNeedsContract::class);

        $graph = (new ContainerGraphInspector())->inspect($container);

        self::assertTrue($graph->hasIssues());

        $issuesByCode = [];

        foreach ($graph->issues() as $issue) {
            $issuesByCode[$issue->code][] = $issue;
        }

        self::assertArrayHasKey('missing_class', $issuesByCode);
        self::assertSame('graph.missing', $issuesByCode['missing_class'][0]->service);
        self::assertSame('Vendor\\Missing\\Service', $issuesByCode['missing_class'][0]->subject);

        self::assertArrayHasKey('unresolvable_parameter', $issuesByCode);
        self::assertSame(GraphNeedsScalar::class, $issuesByCode['unresolvable_parameter'][0]->service);
        self::assertSame('$token', $issuesByCode['unresolvable_parameter'][0]->subject);

        self::assertArrayHasKey('not_instantiable', $issuesByCode);
        self::assertSame(GraphAbstractTarget::class, $issuesByCode['not_instantiable'][0]->service);
        self::assertSame(GraphAbstractTarget::class, $issuesByCode['not_instantiable'][0]->subject);

        self::assertArrayHasKey('unresolvable_dependency', $issuesByCode);
        self::assertSame(GraphNeedsContract::class, $issuesByCode['unresolvable_dependency'][0]->service);
        self::assertSame(GraphContract::class, $issuesByCode['unresolvable_dependency'][0]->subject);

        self::assertSame('definition', $issuesByCode['missing_class'][0]->phase);
        self::assertSame('binding', $issuesByCode['missing_class'][0]->origin);
        self::assertSame(['graph.missing'], $issuesByCode['missing_class'][0]->path);
        self::assertNotNull($issuesByCode['missing_class'][0]->remediation);

        self::assertSame('autowiring', $issuesByCode['unresolvable_parameter'][0]->phase);
        self::assertSame('$token', $issuesByCode['unresolvable_parameter'][0]->parameter);
        self::assertSame([GraphNeedsScalar::class], $issuesByCode['unresolvable_parameter'][0]->path);
    }

    public function test_it_does_not_report_contract_dependencies_when_a_binding_exists_for_the_contract(): void
    {
        $container = new Container();
        $container->bind(GraphContract::class, GraphContractImplementation::class);
        $container->bind(GraphNeedsContract::class, GraphNeedsContract::class);

        $graph = (new ContainerGraphInspector())->inspect($container);

        $unresolvable = array_values(array_filter(
            $graph->issues(),
            static fn(object $issue): bool => $issue->code === 'unresolvable_dependency'
        ));

        self::assertSame([], $unresolvable);
    }

    public function test_it_reports_static_cycles_between_registered_analyzable_services(): void
    {
        $container = new Container();
        $container->bind(GraphCycleA::class, GraphCycleA::class);
        $container->bind(GraphCycleB::class, GraphCycleB::class);

        $graph = (new ContainerGraphInspector())->inspect($container);

        $cycles = array_values(array_filter(
            $graph->issues(),
            static fn(object $issue): bool => $issue->code === 'dependency_cycle'
        ));

        self::assertCount(1, $cycles);
        self::assertStringContainsString(GraphCycleA::class, $cycles[0]->message);
        self::assertStringContainsString(GraphCycleB::class, $cycles[0]->message);
        self::assertSame('cycle_detection', $cycles[0]->phase);
        self::assertSame('constructor', $cycles[0]->origin);
        self::assertSame(
            [GraphCycleA::class, GraphCycleB::class, GraphCycleA::class],
            $cycles[0]->path,
        );
    }

    public function test_it_reports_static_lifetime_and_scope_capture_violations_for_registered_services(): void
    {
        $container = new Container();
        $container->singleton(GraphSingletonRetainsRequest::class, GraphSingletonRetainsRequest::class);
        $container->scopedFor(GraphRequestScopedDependency::class, GraphRequestScopedDependency::class, 'request');
        $container->scopedFor(GraphRequestRetainsTenant::class, GraphRequestRetainsTenant::class, 'request');
        $container->scopedFor(GraphTenantScopedDependency::class, GraphTenantScopedDependency::class, 'tenant');
        $container->scopedFor(GraphTenantRetainsRequest::class, GraphTenantRetainsRequest::class, 'tenant');

        $graph = (new ContainerGraphInspector())->inspect($container);

        $issues = array_values(array_filter(
            $graph->issues(),
            static fn(object $issue): bool => $issue->code === 'scope_capture_violation'
        ));

        self::assertCount(2, $issues);

        $byService = [];

        foreach ($issues as $issue) {
            $byService[$issue->service] = $issue;
        }

        self::assertArrayHasKey(GraphSingletonRetainsRequest::class, $byService);
        self::assertSame(GraphRequestScopedDependency::class, $byService[GraphSingletonRetainsRequest::class]->subject);
        self::assertStringContainsString('singleton', $byService[GraphSingletonRetainsRequest::class]->message);
        self::assertStringContainsString('request', $byService[GraphSingletonRetainsRequest::class]->message);
        self::assertSame('scope_propagation', $byService[GraphSingletonRetainsRequest::class]->phase);
        self::assertSame(
            [GraphSingletonRetainsRequest::class, GraphRequestScopedDependency::class],
            $byService[GraphSingletonRetainsRequest::class]->path,
        );

        self::assertArrayHasKey(GraphRequestRetainsTenant::class, $byService);
        self::assertSame(GraphTenantScopedDependency::class, $byService[GraphRequestRetainsTenant::class]->subject);
        self::assertStringContainsString('request', $byService[GraphRequestRetainsTenant::class]->message);
        self::assertStringContainsString('tenant', $byService[GraphRequestRetainsTenant::class]->message);
    }

    public function test_it_does_not_report_static_scope_capture_for_compatible_registered_scopes(): void
    {
        $container = new Container();
        $container->scopedFor(GraphTenantRetainsRequest::class, GraphTenantRetainsRequest::class, 'tenant');
        $container->scopedFor(GraphRequestScopedDependency::class, GraphRequestScopedDependency::class, 'request');

        $graph = (new ContainerGraphInspector())->inspect($container);

        $issues = array_values(array_filter(
            $graph->issues(),
            static fn(object $issue): bool => $issue->code === 'scope_capture_violation'
        ));

        self::assertSame([], $issues);
    }

    public function test_it_reports_indirect_scope_capture_through_registered_transient_services(): void
    {
        $container = new Container();
        $container->singleton(GraphSingletonRetainsRequestTransitively::class, GraphSingletonRetainsRequestTransitively::class);
        $container->bind(GraphTransientBridgeToRequest::class, GraphTransientBridgeToRequest::class);
        $container->scopedFor(GraphRequestScopedDependency::class, GraphRequestScopedDependency::class, 'request');
        $container->scopedFor(GraphRequestRetainsTenantTransitively::class, GraphRequestRetainsTenantTransitively::class, 'request');
        $container->bind(GraphTransientBridgeToTenant::class, GraphTransientBridgeToTenant::class);
        $container->scopedFor(GraphTenantScopedDependency::class, GraphTenantScopedDependency::class, 'tenant');

        $graph = (new ContainerGraphInspector())->inspect($container);

        $issues = array_values(array_filter(
            $graph->issues(),
            static fn(object $issue): bool => $issue->code === 'scope_capture_violation'
        ));

        $byService = [];

        foreach ($issues as $issue) {
            $byService[$issue->service][] = $issue;
        }

        self::assertCount(1, $byService[GraphSingletonRetainsRequestTransitively::class] ?? []);
        self::assertSame(
            GraphRequestScopedDependency::class,
            $byService[GraphSingletonRetainsRequestTransitively::class][0]->subject,
        );
        self::assertStringContainsString(GraphTransientBridgeToRequest::class, $byService[GraphSingletonRetainsRequestTransitively::class][0]->message);
        self::assertSame(
            [
                GraphSingletonRetainsRequestTransitively::class,
                GraphTransientBridgeToRequest::class,
                GraphRequestScopedDependency::class,
            ],
            $byService[GraphSingletonRetainsRequestTransitively::class][0]->path,
        );

        self::assertCount(1, $byService[GraphRequestRetainsTenantTransitively::class] ?? []);
        self::assertSame(
            GraphTenantScopedDependency::class,
            $byService[GraphRequestRetainsTenantTransitively::class][0]->subject,
        );
        self::assertStringContainsString(GraphTransientBridgeToTenant::class, $byService[GraphRequestRetainsTenantTransitively::class][0]->message);
    }

    public function test_it_does_not_propagate_scope_capture_through_registered_singleton_boundaries(): void
    {
        $container = new Container();
        $container->singleton(GraphSingletonParentOfSingleton::class, GraphSingletonParentOfSingleton::class);
        $container->singleton(GraphSingletonRetainsRequest::class, GraphSingletonRetainsRequest::class);
        $container->scopedFor(GraphRequestScopedDependency::class, GraphRequestScopedDependency::class, 'request');

        $graph = (new ContainerGraphInspector())->inspect($container);

        $issues = array_values(array_filter(
            $graph->issues(),
            static fn(object $issue): bool => $issue->code === 'scope_capture_violation'
        ));

        $byService = [];

        foreach ($issues as $issue) {
            $byService[$issue->service][] = $issue;
        }

        self::assertArrayHasKey(GraphSingletonRetainsRequest::class, $byService);
        self::assertArrayNotHasKey(GraphSingletonParentOfSingleton::class, $byService);
    }
}

final class GraphDependency
{
}

final class GraphConsumer
{
    public function __construct(
        public readonly GraphDependency $dependency,
        public readonly string $label = 'graph',
    ) {
    }
}

final class GraphNeedsScalar
{
    public function __construct(public readonly string $token)
    {
    }
}

interface GraphContract
{
}

final class GraphContractImplementation implements GraphContract
{
}

final class GraphNeedsContract
{
    public function __construct(public readonly GraphContract $contract)
    {
    }
}

final class GraphCycleA
{
    public function __construct(public readonly GraphCycleB $b)
    {
    }
}

final class GraphCycleB
{
    public function __construct(public readonly GraphCycleA $a)
    {
    }
}

final class GraphRequestScopedDependency
{
}

final class GraphTenantScopedDependency
{
}

final class GraphSingletonRetainsRequest
{
    public function __construct(public readonly GraphRequestScopedDependency $dependency)
    {
    }
}

final class GraphRequestRetainsTenant
{
    public function __construct(public readonly GraphTenantScopedDependency $dependency)
    {
    }
}

final class GraphTenantRetainsRequest
{
    public function __construct(public readonly GraphRequestScopedDependency $dependency)
    {
    }
}

final class GraphTransientBridgeToRequest
{
    public function __construct(public readonly GraphRequestScopedDependency $dependency)
    {
    }
}

final class GraphTransientBridgeToTenant
{
    public function __construct(public readonly GraphTenantScopedDependency $dependency)
    {
    }
}

final class GraphSingletonRetainsRequestTransitively
{
    public function __construct(public readonly GraphTransientBridgeToRequest $dependency)
    {
    }
}

final class GraphRequestRetainsTenantTransitively
{
    public function __construct(public readonly GraphTransientBridgeToTenant $dependency)
    {
    }
}

final class GraphSingletonParentOfSingleton
{
    public function __construct(public readonly GraphSingletonRetainsRequest $dependency)
    {
    }
}

abstract class GraphAbstractTarget
{
}
