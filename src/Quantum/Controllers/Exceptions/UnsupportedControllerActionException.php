<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class UnsupportedControllerActionException extends ControllerException
{
    public function __construct()
    {
        parent::__construct('Unsupported controller route action.');
    }

    public function errorCode(): string
    {
        return 'controller.unsupported_action';
    }
}

