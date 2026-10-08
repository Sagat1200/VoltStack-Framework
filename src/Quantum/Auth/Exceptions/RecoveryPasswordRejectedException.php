<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class RecoveryPasswordRejectedException extends AuthenticationException
{
    public function __construct(
        string $message = 'The new password does not satisfy the configured password policy.',
        string $reasonCode = 'auth.recovery.password_rejected',
    ) {
        parent::__construct($message, $reasonCode);
    }
}
