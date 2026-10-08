<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

use Quantum\Container\AliasResolver;
use Closure;
use Quantum\Container\Binding;
use Quantum\Container\Container;
use Quantum\Container\ScopeKind;
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
        array_push($issues, ...$this->validateScopeCaptures($services, $aliases));
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
    private function validateScopeCaptures(array $services, array $aliases): array
    {
        $issues = [];
        $reported = [];

        foreach ($services as $service) {
            if (! in_array($service->lifetime, ['singleton', 'scoped'], true)) {
                continue;
            }

            $this->walkForScopeCaptures(
                root: $service,
                current: $service,
                services: $services,
                aliases: $aliases,
                activePath: [$service->abstract],
                visitedTransients: [],
                reported: $reported,
                issues: $issues,
            );
        }

        return $issues;
    }

    /**
     * @param array<string, ServiceNode> $services
     * @param array<string, string> $aliases
     * @param list<string> $activePath
     * @param array<string, bool> $visitedTransients
     * @param array<string, bool> $reported
     * @param list<ValidationIssue> $issues
     */
    private function walkForScopeCaptures(
        ServiceNode $root,
        ServiceNode $current,
        array $services,
        array $aliases,
        array $activePath,
        array $visitedTransients,
        array &$reported,
        array &$issues,
    ): void {
        foreach ($current->dependencies as $dependency) {
            $target = $this->normalizeAbstract($dependency->abstract, $aliases);
            $dependencyNode = $services[$target] ?? null;

            if ($dependencyNode === null) {
                continue;
            }

            if ($dependencyNode->lifetime === 'scoped') {
                $issue = $this->scopeCaptureIssue($root, $dependencyNode, [...$activePath, $dependencyNode->abstract]);

                if ($issue === null) {
                    continue;
                }

                $signature = $issue->service . '->' . ($issue->subject ?? '');

                if (($reported[$signature] ?? false) === true) {
                    continue;
                }

                $reported[$signature] = true;
                $issues[] = $issue;
                continue;
            }

            if ($dependencyNode->lifetime !== 'transient') {
                continue;
            }

            if (($visitedTransients[$dependencyNode->abstract] ?? false) === true) {
                continue;
            }

            $visitedTransients[$dependencyNode->abstract] = true;

            $this->walkForScopeCaptures(
                root: $root,
                current: $dependencyNode,
                services: $services,
                aliases: $aliases,
                activePath: [...$activePath, $dependencyNode->abstract],
                visitedTransients: $visitedTransients,
                reported: $reported,
                issues: $issues,
            );
        }
    }

    /**
     * @param list<string> $path
     */
    private function scopeCaptureIssue(ServiceNode $service, ServiceNode $dependencyNode, array $path): ?ValidationIssue
    {
        $pathDescription = implode(' -> ', $path);

        if ($service->lifetime === 'singleton') {
            return new ValidationIssue(
                'scope_capture_violation',
                sprintf(
                    'Service [%s] is a singleton and cannot statically retain scoped dependency [%s]%s via path [%s].',
                    $service->abstract,
                    $dependencyNode->abstract,
                    $dependencyNode->scopeKind !== null
                        ? sprintf(' with scope [%s]', $dependencyNode->scopeKind)
                        : '',
                    $pathDescription,
                ),
                $service->abstract,
                $dependencyNode->abstract,
            );
        }

        $serviceScopeKind = $this->scopeKindFromNode($service);
        $dependencyScopeKind = $this->scopeKindFromNode($dependencyNode);

        if ($serviceScopeKind === null || $dependencyScopeKind === null) {
            return null;
        }

        if ($serviceScopeKind->canRetain($dependencyScopeKind)) {
            return null;
        }

        return new ValidationIssue(
            'scope_capture_violation',
            sprintf(
                'Scoped service [%s] with scope [%s] cannot statically retain dependency [%s] with scope [%s] via path [%s].',
                $service->abstract,
                $serviceScopeKind->value,
                $dependencyNode->abstract,
                $dependencyScopeKind->value,
                $pathDescription,
            ),
            $service->abstract,
            $dependencyNode->abstract,
        );
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

    private function scopeKindFromNode(ServiceNode $node): ?ScopeKind
    {
        if ($node->lifetime !== 'scoped' || $node->scopeKind === null || $node->scopeKind === '') {
            return null;
        }

        return ScopeKind::fromName($node->scopeKind);
    }
}
