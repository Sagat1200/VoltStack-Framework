<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Container\AliasResolver;
use Quantum\Container\ClassBuilder;
use Quantum\Container\ConcreteResolver;
use Quantum\Container\Container;
use Quantum\Container\Exceptions\BindingResolutionException;
use Quantum\Container\ParameterResolver;
use ReflectionClass;

final class ContainerPrimitivesTest extends TestCase
{
    public function test_alias_resolver_normalizes_alias_chains(): void
    {
        $resolver = new AliasResolver();

        $normalized = $resolver->normalize('service.alias', [
            'service.alias' => 'service.bridge',
            'service.bridge' => 'service.target',
        ]);

        self::assertSame('service.target', $normalized);
    }

    public function test_alias_resolver_rejects_cycles(): void
    {
        $resolver = new AliasResolver();

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Circular alias detected');

        $resolver->normalize('service.a', [
            'service.a' => 'service.b',
            'service.b' => 'service.a',
        ]);
    }

    public function test_concrete_and_parameter_resolvers_build_an_object_graph(): void
    {
        $container = new Container();
        $parameterResolver = new ParameterResolver();
        $classBuilder = new ClassBuilder();
        $concreteResolver = new ConcreteResolver();

        $built = $concreteResolver->resolve(
            ContainerPrimitiveConsumer::class,
            [],
            $container,
            fn(string $className, array $parameters): object => $classBuilder->build(
                $className,
                $parameters,
                fn(object $parameter, array $runtimeParameters): mixed => $parameterResolver->resolve(
                    $parameter,
                    $runtimeParameters,
                    fn(string $abstract): mixed => $container->make($abstract),
                ),
            ),
        );

        self::assertInstanceOf(ContainerPrimitiveConsumer::class, $built);
        self::assertInstanceOf(ContainerPrimitiveDependency::class, $built->dependency);
        self::assertSame('fallback', $built->label);
    }

    public function test_parameter_resolver_prefers_named_and_typed_overrides(): void
    {
        $resolver = new ParameterResolver();
        $reflector = new ReflectionClass(ContainerPrimitiveConsumer::class);
        $parameters = $reflector->getConstructor()?->getParameters() ?? [];
        $override = new ContainerPrimitiveDependency();

        self::assertSame(
            $override,
            $resolver->resolve(
                $parameters[0],
                [ContainerPrimitiveDependency::class => $override],
                static fn(): never => throw new BindingResolutionException('Should not resolve from container.'),
            ),
        );

        self::assertSame(
            'named-value',
            $resolver->resolve(
                $parameters[1],
                ['label' => 'named-value'],
                static fn(): never => throw new BindingResolutionException('Should not resolve from container.'),
            ),
        );
    }
}

final class ContainerPrimitiveDependency
{
}

final class ContainerPrimitiveConsumer
{
    public function __construct(
        public readonly ContainerPrimitiveDependency $dependency,
        public readonly string $label = 'fallback',
    ) {
    }
}
