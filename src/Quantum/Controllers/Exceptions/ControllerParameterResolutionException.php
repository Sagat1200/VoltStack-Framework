<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class ControllerParameterResolutionException extends ControllerException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('Unable to resolve controller arguments.', 0, $previous);
    }

    public function errorCode(): string
    {
        return 'controller.parameter_resolution_failed';
    }
}

