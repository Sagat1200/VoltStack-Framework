<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors\Contracts;

use Quantum\Controllers\Execution\ControllerExecution;

interface ControllerInterceptorChainInterface
{
    public function proceed(ControllerExecution $execution): mixed;
}

