<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Controllers\ControllerContextInjector;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\ResolvedController;

final class ControllerInvoker
{
    public function __construct(private readonly ControllerContextInjector $injector) {}

    public function invoke(ResolvedController $controller, array $arguments, ControllerExecutionContext $context): mixed
    {
        $instance = $controller->instance();
        $method = $controller->method();

        $this->injector->inject($instance, $context);

        try {
            return $instance->{$method}(...$arguments);
        } finally {
            $this->injector->release($instance);
        }
    }
}
