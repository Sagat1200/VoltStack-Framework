<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class InvalidCredentialsException extends AuthenticationException
{
    public function __construct(string $message = 'Invalid credentials.')
    {
        parent::__construct($message, 'auth.invalid_credentials');
    }
}
