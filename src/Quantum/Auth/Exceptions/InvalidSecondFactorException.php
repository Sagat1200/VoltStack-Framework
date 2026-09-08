<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class InvalidSecondFactorException extends AuthenticationException
{
    public function __construct(string $message = 'Invalid second factor.')
    {
        parent::__construct($message, 'auth.invalid_second_factor');
    }
}
