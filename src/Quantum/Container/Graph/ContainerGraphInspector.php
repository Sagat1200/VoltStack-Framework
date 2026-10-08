<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

use Closure;
use Quantum\Container\Binding;
use Quantum\Container\Container;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

final class ContainerGraphInspector
{
    public function inspect(Container $container): ContainerGraph
    {
        $services = [];
        $issues = [];

        foreach ($container->registeredBindings() as $abstract => $binding) {
            [$node, $serviceIssues] = $this->inspectBinding($binding);
            $services[$abstract] = $node;
            array_push($issues, ...$serviceIssues);
        }

        return new ContainerGraph(
            $services,
            $container->registeredAliases(),
            $issues,
        );
    }

    /**
     * @return array{ServiceNode, list<ValidationIssue>}
     */
    private function inspectBinding(Binding $binding): array
    {
        $concrete = $binding->concrete;
        $dependencies = [];
        $parameters = [];
        $issues = [];
        $analyzable = false;
        $concreteKind = $this->concreteKind($concrete);
        $concreteName = $this->concreteName($concrete);

        if (is_string($concrete)) {
            if (! class_exists($concrete)) {
                $issues[] = new ValidationIssue(
                    'missing_class',
                    sprintf('Binding [%s] targets class [%s], but that class does not exist.', $binding->abstract, $concrete),
                    $binding->abstract,
                    $concrete,
                );
            } else {
                $analyzable = true;
                [$dependencies, $parameters, $issues] = $this->inspectClassTarget($binding->abstract, $concrete);
            }
        }

        return [
            new ServiceNode(
                abstract: $binding->abstract,
                lifetime: $binding->lifetime(),
                scopeKind: $binding->scopeKindName(),
                concreteKind: $concreteKind,
                concreteName: $concreteName,
                analyzable: $analyzable,
                dependencies: $dependencies,
                parameters: $parameters,
            ),
            $issues,
        ];
    }

    /**
     * @return array{list<DependencyReference>, list<ParameterRequirement>, list<ValidationIssue>}
     */
    private function inspectClassTarget(string $abstract, string $concrete): array
    {
        $reflector = new ReflectionClass($concrete);

        if (! $reflector->isInstantiable()) {
            return [
                [],
                [],
                [
                    new ValidationIssue(
                        'not_instantiable',
                        sprintf('Binding [%s] targets [%s], but that class is not instantiable.', $abstract, $concrete),
                        $abstract,
                        $concrete,
                    ),
                ],
            ];
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return [[], [], []];
        }

        $dependencies = [];
        $parameters = [];
        $issues = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $dependencies[] = new DependencyReference(
                    abstract: $type->getName(),
                    optional: $parameter->isDefaultValueAvailable(),
                );
                continue;
            }

            $requirement = new ParameterRequirement(
                name: $parameter->getName(),
                type: $type instanceof ReflectionNamedType ? $type->getName() : $this->typeName($type),
                builtin: $type instanceof ReflectionNamedType ? $type->isBuiltin() : true,
                hasDefault: $parameter->isDefaultValueAvailable(),
            );

            $parameters[] = $requirement;

            if (! $parameter->isDefaultValueAvailable()) {
                $issues[] = new ValidationIssue(
                    'unresolvable_parameter',
                    sprintf(
                        'Binding [%s] targets [%s], but constructor parameter [$%s] cannot be resolved statically.',
                        $abstract,
                        $concrete,
                        $parameter->getName(),
                    ),
                    $abstract,
                    '$' . $parameter->getName(),
                );
            }
        }

        return [$dependencies, $parameters, $issues];
    }

    private function concreteKind(mixed $concrete): string
    {
        if ($concrete instanceof Closure) {
            return 'closure';
        }

        if (is_string($concrete)) {
            return 'class';
        }

        if (is_object($concrete)) {
            return 'object';
        }

        return get_debug_type($concrete);
    }

    private function concreteName(mixed $concrete): ?string
    {
        if ($concrete instanceof Closure) {
            return 'Closure';
        }

        if (is_string($concrete)) {
            return $concrete;
        }

        if (is_object($concrete)) {
            return $concrete::class;
        }

        return null;
    }

    private function typeName(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        return method_exists($type, '__toString') ? (string) $type : $type::class;
    }
}
