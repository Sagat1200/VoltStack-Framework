<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Engine;

use Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface;
use Quantum\Controllers\Observability\Contracts\ControllerEventInterface;

final class NullControllerEventDispatcher implements ControllerEventDispatcherInterface
{
    public function dispatch(ControllerEventInterface $event): void {}
}