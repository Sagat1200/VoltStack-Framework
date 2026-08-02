<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

use Quantum\Controllers\Exceptions\InvalidInterceptorConditionException;
use Quantum\Controllers\Exceptions\InvalidInterceptorException;
use Quantum\Controllers\Exceptions\UnknownInterceptorException;
use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\Conditions\InterceptorConditionRegistry;
use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorInterface;
use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorRegistryInterface;
use Quantum\Controllers\Interceptors\InterceptorPhase;
use Quantum\Controllers\Interceptors\InterceptorScope;

final class ControllerInterceptorResolver
{
    public function __construct(
        private readonly ControllerInterceptorRegistryInterface $registry,
        private readonly InterceptorConditionRegistry $conditions,
        private readonly ControllerInterceptorPlanBuilder $builder,
    ) {}

    public function resolve(ControllerExecution $execution): ControllerInterceptorPlan
    {
        $raw = $execution->context()
            ->match()
            ->route()
            ->routeMetadata()
            ->get('controller.interceptors', []);

        if (! is_array($raw)) {
            $raw = [];
        }

        $definitions = [];
        $index = 0;

        foreach ($raw as $item) {
            if (is_string($item)) {
                $item = trim($item);

                if ($item === '') {
                    continue;
                }

                $resolved = $this->resolveDefinition(
                    new InterceptorDefinition($item),
                    $index,
                );

                if ($resolved !== null) {
                    $definitions[] = $resolved;
                    $index++;
                }

                continue;
            }

            if (is_array($item)) {
                $definition = $this->parseArrayDefinition($item);

                if ($definition === null) {
                    continue;
                }

                $resolved = $this->resolveDefinition($definition, $index);

                if ($resolved !== null) {
                    $definitions[] = $resolved;
                    $index++;
                }
            }
        }

        $deduped = $this->deduplicate($definitions);

        return $this->builder->build($deduped);
    }

    private function parseArrayDefinition(array $item): ?InterceptorDefinition
    {
        $interceptor = $item['interceptor'] ?? null;

        if (! is_string($interceptor) || trim($interceptor) === '') {
            return null;
        }

        $arguments = $item['arguments'] ?? [];
        $priority = $item['priority'] ?? null;
        $conditions = $item['conditions'] ?? [];
        $phase = $item['phase'] ?? null;
        $scope = $item['scope'] ?? null;

        if (! is_array($arguments)) {
            $arguments = [];
        }

        if (! is_null($priority) && ! is_int($priority)) {
            if (is_numeric($priority)) {
                $priority = (int) $priority;
            } else {
                $priority = null;
            }
        }

        if (! is_array($conditions)) {
            $conditions = [];
        }

        return new InterceptorDefinition(
            interceptor: trim($interceptor),
            arguments: $arguments,
            priority: $priority,
            conditions: $conditions,
            phase: $phase instanceof InterceptorPhase ? $phase : null,
            scope: $scope instanceof InterceptorScope ? $scope : null,
        );
    }

    private function resolveDefinition(InterceptorDefinition $definition, int $orderIndex): ?ResolvedInterceptorDefinition
    {
        $candidate = $this->registry->resolveAlias($definition->interceptor);
        $conditionInstances = $this->resolveConditions($definition->conditions);

        if ($this->registry->has($candidate)) {
            $descriptor = $this->registry->get($candidate);

            $this->assertValidInterceptor($descriptor->interceptor);

            return new ResolvedInterceptorDefinition(
                interceptorClass: $descriptor->interceptor,
                arguments: $definition->arguments,
                priority: $definition->priority ?? $descriptor->defaultPriority,
                phase: $definition->phase ?? $descriptor->defaultPhase,
                scope: $definition->scope ?? $descriptor->scope,
                orderIndex: $orderIndex,
                conditions: $conditionInstances,
                repeatable: $descriptor->repeatable,
            );
        }

        if (class_exists($candidate)) {
            $this->assertValidInterceptor($candidate);

            return new ResolvedInterceptorDefinition(
                interceptorClass: $candidate,
                arguments: $definition->arguments,
                priority: $definition->priority ?? 0,
                phase: $definition->phase ?? InterceptorPhase::Around,
                scope: $definition->scope ?? InterceptorScope::Execution,
                orderIndex: $orderIndex,
                conditions: $conditionInstances,
                repeatable: false,
            );
        }

        throw new UnknownInterceptorException($candidate);
    }

    private function assertValidInterceptor(string $interceptor): void
    {
        if (! is_subclass_of($interceptor, ControllerInterceptorInterface::class)) {
            throw new InvalidInterceptorException($interceptor);
        }
    }

    private function resolveConditions(array $conditions): array
    {
        $resolved = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                throw new InvalidInterceptorConditionException();
            }

            $type = $condition['type'] ?? null;

            if (! is_string($type) || trim($type) === '') {
                throw new InvalidInterceptorConditionException();
            }

            $resolved[] = $this->conditions->make(trim($type), $condition['value'] ?? null);
        }

        return $resolved;
    }

    private function deduplicate(array $definitions): array
    {
        $seen = [];
        $result = [];

        foreach ($definitions as $definition) {
            $key = $definition->interceptorClass;

            if (isset($seen[$key]) && ! $definition->repeatable) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $definition;
        }

        return $result;
    }
}
