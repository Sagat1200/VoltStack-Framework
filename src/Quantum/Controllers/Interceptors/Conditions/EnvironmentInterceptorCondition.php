<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors\Conditions;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\Conditions\Contracts\InterceptorConditionInterface;
use Quantum\Controllers\Interceptors\ResolvedInterceptorDefinition;

final readonly class EnvironmentInterceptorCondition implements InterceptorConditionInterface
{
    public function __construct(private mixed $value) {}

    public function matches(ControllerExecution $execution, ResolvedInterceptorDefinition $definition): bool
    {
        $environment = $execution->context()->app()->environment();

        if (is_string($this->value)) {
            return strtolower(trim($this->value)) === $environment;
        }

        if (is_array($this->value)) {
            foreach ($this->value as $item) {
                if (is_string($item) && strtolower(trim($item)) === $environment) {
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

