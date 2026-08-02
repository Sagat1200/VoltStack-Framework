<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Controllers\Contracts\ControllerExecutionContextAwareInterface;

final class ControllerContextInjector
{
    public function inject(object $controller, ControllerExecutionContext $context): void
    {
        if (! $controller instanceof ControllerExecutionContextAwareInterface) {
            return;
        }

        $controller->setControllerExecutionContext($context);
    }

    public function release(object $controller): void
    {
        if (! $controller instanceof ControllerExecutionContextAwareInterface) {
            return;
        }

        $controller->releaseControllerExecutionContext();
    }
}