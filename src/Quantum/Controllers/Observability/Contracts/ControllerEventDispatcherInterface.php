<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Contracts;

interface ControllerEventDispatcherInterface
{
    public function dispatch(ControllerEventInterface $event): void;
}

