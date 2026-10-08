<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class RecoveryTokenExpiredException extends AuthenticationException
{
    public function __construct(
        string $message = 'The recovery token has expired. Request a new password reset link.',
        string $reasonCode = 'auth.recovery.token_expired',
    ) {
        parent::__construct($message, $reasonCode);
    }
}
