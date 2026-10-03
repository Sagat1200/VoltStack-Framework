<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Container\Container;
use Quantum\Container\Exceptions\BindingResolutionException;

final class ContainerRegressionTest extends TestCase
{
    public function test_aliases_resolve_the_underlying_singleton_identity(): void
    {
        $container = new Container();
        $container->singleton(ContainerRegressionDependency::class);
        $container->alias(ContainerRegressionDependency::class, 'container.dependency');

        $fromAbstract = $container->make(ContainerRegressionDependency::class);
        $fromAlias = $container->make('container.dependency');

        self::assertSame($fromAbstract, $fromAlias);
        self::assertTrue($container->has('container.dependency'));
    }

    public function test_instance_registration_takes_precedence_over_bindings(): void
    {
        $container = new Container();
        $expected = new ContainerRegressionDependency();

        $container->bind(ContainerRegressionDependency::class, fn (): ContainerRegressionDependency => new ContainerRegressionDependency());
        $container->instance(ContainerRegressionDependency::class, $expected);

        self::assertSame($expected, $container->make(ContainerRegressionDependency::class));
    }

    public function test_scoped_instance_is_cleared_when_the_scope_is_flushed(): void
    {
        $container = new Container();
        $first = new ContainerRegressionDependency();
        $second = new ContainerRegressionDependency();

        $container->scopedInstance(ContainerRegressionDependency::class, $first);

        self::assertSame($first, $container->make(ContainerRegressionDependency::class));
        self::assertTrue($container->resolved(ContainerRegressionDependency::class));

        $container->flushScope();
        $container->scopedInstance(ContainerRegressionDependency::class, $second);

        self::assertSame($second, $container->make(ContainerRegressionDependency::class));
        self::assertNotSame($first, $second);
    }

    public function test_type_name_parameter_override_is_supported_for_class_dependencies(): void
    {
        $container = new Container();
        $override = new ContainerRegressionDependency();

        $consumer = $container->make(ContainerRegressionConsumer::class, [
            ContainerRegressionDependency::class => $override,
        ]);

        self::assertSame($override, $consumer->dependency);
    }

    public function test_has_returns_true_for_autowireable_classes_without_explicit_binding(): void
    {
        $container = new Container();

        self::assertTrue($container->has(ContainerRegressionDependency::class));
        self::assertFalse($container->has('container.missing'));
    }

    public function test_has_returns_true_for_explicit_null_instances(): void
    {
        $container = new Container();
        $container->instance('container.null', null);

        self::assertTrue($container->has('container.null'));
        self::assertNull($container->make('container.null'));
    }

    public function test_unresolvable_scalar_dependency_throws_a_binding_resolution_exception(): void
    {
        $container = new Container();

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Unable to resolve dependency [name]');

        $container->make(ContainerRegressionScalarConsumer::class);
    }

    public function test_non_instantiable_targets_throw_a_binding_resolution_exception(): void
    {
        $container = new Container();

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('is not instantiable');

        $container->make(ContainerRegressionAbstractDependency::class);
    }

    public function test_circular_aliases_throw_a_binding_resolution_exception(): void
    {
        $container = new Container();
        $container->alias('container.second', 'container.first');
        $container->alias('container.first', 'container.second');

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Circular alias detected');

        $container->make('container.first');
    }
}

final class ContainerRegressionDependency
{
}

final class ContainerRegressionConsumer
{
    public function __construct(public readonly ContainerRegressionDependency $dependency)
    {
    }
}

final class ContainerRegressionScalarConsumer
{
    public function __construct(public readonly string $name)
    {
    }
}

abstract class ContainerRegressionAbstractDependency
{
}
