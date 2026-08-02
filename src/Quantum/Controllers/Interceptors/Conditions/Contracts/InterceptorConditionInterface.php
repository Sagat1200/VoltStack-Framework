<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors\Conditions\Contracts;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\ResolvedInterceptorDefinition;

interface InterceptorConditionInterface
{
    public function matches(
        ControllerExecution $execution,
        ResolvedInterceptorDefinition $definition,
    ): bool;

    public function isStatic(): bool;
}

