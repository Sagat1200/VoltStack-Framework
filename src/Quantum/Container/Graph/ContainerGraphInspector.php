<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

use Quantum\Container\AliasResolver;
use Closure;
use Quantum\Container\Binding;
use Quantum\Container\Container;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;

final class ContainerGraphInspector
{
    private AliasResolver $aliasResolver;

    public function __construct(?AliasResolver $aliasResolver = null)
    {
        $this->aliasResolver = $aliasResolver ?? new AliasResolver();
    }

    public function inspect(Container $container): ContainerGraph
    {
        $services = [];
        $issues = [];
        $aliases = $container->registeredAliases();

        foreach ($container->registeredBindings() as $abstract => $binding) {
            [$node, $serviceIssues] = $this->inspectBinding($binding);
            $services[$abstract] = $node;
            array_push($issues, ...$serviceIssues);
        }

        array_push($issues, ...$this->validateResolvableDependencies($services, $aliases));
        array_push($issues, ...$this->detectRegisteredCycles($services, $aliases));

        return new ContainerGraph(
            $services,
            $aliases,
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

    /**
     * @param array<string, ServiceNode> $services
     * @param array<string, string> $aliases
     * @return list<ValidationIssue>
     */
    private function validateResolvableDependencies(array $services, array $aliases): array
    {
        $issues = [];

        foreach ($services as $service) {
            foreach ($service->dependencies as $dependency) {
                $target = $this->normalizeAbstract($dependency->abstract, $aliases);

                if (array_key_exists($target, $services)) {
                    continue;
                }

                if (class_exists($target)) {
                    $reflector = new ReflectionClass($target);

                    if ($reflector->isInstantiable()) {
                        continue;
                    }
                }

                $issues[] = new ValidationIssue(
                    'unresolvable_dependency',
                    sprintf(
                        'Service [%s] depends on [%s], but the target is neither a registered binding nor an instantiable class.',
                        $service->abstract,
                        $target,
                    ),
                    $service->abstract,
                    $target,
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string, ServiceNode> $services
     * @param array<string, string> $aliases
     * @return list<ValidationIssue>
     */
    private function detectRegisteredCycles(array $services, array $aliases): array
    {
        $issues = [];
        $visited = [];
        $activePath = [];
        $reported = [];

        foreach (array_keys($services) as $abstract) {
            $this->walkForCycles($abstract, $services, $aliases, $visited, $activePath, $reported, $issues);
        }

        return $issues;
    }

    /**
     * @param array<string, ServiceNode> $services
     * @param array<string, string> $aliases
     * @param array<string, bool> $visited
     * @param list<string> $activePath
     * @param array<string, bool> $reported
     * @param list<ValidationIssue> $issues
     */
    private function walkForCycles(
        string $abstract,
        array $services,
        array $aliases,
        array &$visited,
        array &$activePath,
        array &$reported,
        array &$issues,
    ): void {
        if (($visited[$abstract] ?? false) === true) {
            return;
        }

        $position = array_search($abstract, $activePath, true);

        if ($position !== false) {
            $cycle = array_slice($activePath, $position);
            $cycle[] = $abstract;
            $signature = $this->cycleSignature($cycle);

            if (($reported[$signature] ?? false) === false) {
                $reported[$signature] = true;
                $issues[] = new ValidationIssue(
                    'dependency_cycle',
                    sprintf(
                        'A static dependency cycle was detected: %s.',
                        implode(' -> ', $cycle),
                    ),
                    $abstract,
                    implode(' -> ', $cycle),
                );
            }

            return;
        }

        $service = $services[$abstract] ?? null;

        if ($service === null || ! $service->analyzable) {
            return;
        }

        $activePath[] = $abstract;

        foreach ($service->dependencies as $dependency) {
            $target = $this->normalizeAbstract($dependency->abstract, $aliases);

            if (! array_key_exists($target, $services)) {
                continue;
            }

            $this->walkForCycles($target, $services, $aliases, $visited, $activePath, $reported, $issues);
        }

        array_pop($activePath);
        $visited[$abstract] = true;
    }

    /**
     * @param list<string> $cycle
     */
    private function cycleSignature(array $cycle): string
    {
        if (count($cycle) <= 2) {
            return implode('->', $cycle);
        }

        array_pop($cycle);
        $best = null;
        $count = count($cycle);

        for ($offset = 0; $offset < $count; $offset++) {
            $rotated = [];

            for ($index = 0; $index < $count; $index++) {
                $rotated[] = $cycle[($offset + $index) % $count];
            }

            $signature = implode('->', $rotated);

            if ($best === null || strcmp($signature, $best) < 0) {
                $best = $signature;
            }
        }

        return (string) $best;
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

    /**
     * @param array<string, string> $aliases
     */
    private function normalizeAbstract(string $abstract, array $aliases): string
    {
        return $this->aliasResolver->normalize($abstract, $aliases);
    }
}
