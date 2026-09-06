<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class AuthenticationRequiredException extends AuthenticationException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message, 'auth.required');
    }
}
