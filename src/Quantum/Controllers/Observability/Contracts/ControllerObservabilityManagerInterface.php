<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Contracts;

use Quantum\Controllers\Execution\ControllerExecution;

interface ControllerObservabilityManagerInterface
{
    public function emit(string $name, ControllerExecution $execution, array $payload = [], int $version = 1): void;
}

