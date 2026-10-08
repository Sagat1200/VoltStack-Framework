<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class RecoveryTokenInvalidException extends AuthenticationException
{
    public function __construct(
        string $message = 'The recovery token is invalid or has already been consumed.',
        string $reasonCode = 'auth.recovery.token_invalid',
    ) {
        parent::__construct($message, $reasonCode);
    }
}
