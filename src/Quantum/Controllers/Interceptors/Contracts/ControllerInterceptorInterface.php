<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors\Contracts;

use Quantum\Controllers\Execution\ControllerExecution;

interface ControllerInterceptorInterface
{
    public function intercept(
        ControllerExecution $execution,
        ControllerInterceptorChainInterface $chain
    ): mixed;
}

