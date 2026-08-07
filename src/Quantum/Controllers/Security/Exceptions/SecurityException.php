<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Exceptions;

use Quantum\Controllers\Exceptions\ControllerException;

class SecurityException extends ControllerException
{
    public function errorCode(): string
    {
        return 'controller.security.error';
    }
}