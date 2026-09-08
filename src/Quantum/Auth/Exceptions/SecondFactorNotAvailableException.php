<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class SecondFactorNotAvailableException extends AuthenticationException
{
    public function __construct(string $message = 'Second factor step-up is not available for this identity.')
    {
        parent::__construct($message, 'auth.second_factor_not_available');
    }
}
