<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class StepUpAuthenticationRequiredException extends AuthenticationException
{
    public function __construct(string $message = 'An authenticated session is required to perform step-up.')
    {
        parent::__construct($message, 'auth.step_up_requires_authentication');
    }
}
