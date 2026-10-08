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

abstract class GraphAbstractTarget
{
}
