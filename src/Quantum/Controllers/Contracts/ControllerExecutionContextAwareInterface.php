<?php

declare(strict_types=1);

namespace Quantum\Controllers\Contracts;

use Quantum\Controllers\ControllerExecutionContext;

interface ControllerExecutionContextAwareInterface
{
    public function setControllerExecutionContext(ControllerExecutionContext $context): void;

    public function releaseControllerExecutionContext(): void;
}