<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Exceptions;

class AuthenticationRequiredException extends SecurityException
{
    public function errorCode(): string
    {
        return 'controller.security.authentication_required';
    }
}