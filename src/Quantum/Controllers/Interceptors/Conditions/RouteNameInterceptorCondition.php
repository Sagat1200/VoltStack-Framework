<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors\Conditions;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\Conditions\Contracts\InterceptorConditionInterface;
use Quantum\Controllers\Interceptors\ResolvedInterceptorDefinition;

final readonly class RouteNameInterceptorCondition implements InterceptorConditionInterface
{
    public function __construct(private mixed $value) {}

    public function matches(ControllerExecution $execution, ResolvedInterceptorDefinition $definition): bool
    {
        $name = $execution->context()
            ->match()
            ->route()
            ->routeMetadata()
            ->get('name');

        if (! is_string($name) || trim($name) === '') {
            return false;
        }

        if (is_string($this->value)) {
            return trim($this->value) === $name;
        }

        if (is_array($this->value)) {
            foreach ($this->value as $item) {
                if (is_string($item) && trim($item) === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isStatic(): bool
    {
        return true;
    }
}

