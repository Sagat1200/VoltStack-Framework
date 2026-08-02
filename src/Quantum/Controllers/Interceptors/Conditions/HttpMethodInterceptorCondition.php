<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors\Conditions;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\Conditions\Contracts\InterceptorConditionInterface;
use Quantum\Controllers\Interceptors\ResolvedInterceptorDefinition;

final readonly class HttpMethodInterceptorCondition implements InterceptorConditionInterface
{
    public function __construct(private mixed $value) {}

    public function matches(ControllerExecution $execution, ResolvedInterceptorDefinition $definition): bool
    {
        $method = strtoupper($execution->context()->request()->method());

        if (is_string($this->value)) {
            return strtoupper(trim($this->value)) === $method;
        }

        if (is_array($this->value)) {
            foreach ($this->value as $item) {
                if (is_string($item) && strtoupper(trim($item)) === $method) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isStatic(): bool
    {
        return false;
    }
}

