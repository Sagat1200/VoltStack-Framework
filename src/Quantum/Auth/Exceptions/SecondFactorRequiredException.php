<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class SecondFactorRequiredException extends AuthenticationException
{
    public function __construct(string $message = 'Second factor verification is required.')
    {
        parent::__construct($message, 'auth.second_factor_required');
    }
}
