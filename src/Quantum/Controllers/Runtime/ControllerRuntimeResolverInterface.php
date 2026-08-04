<?php

declare(strict_types=1);

namespace Quantum\Controllers\Runtime;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Runtime\ControllerRuntimeOptions;

interface ControllerRuntimeResolverInterface
{
    public function resolve(ControllerExecution $execution): ControllerRuntimeOptions;
}
