<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

final class RecoveryPasswordReuseException extends AuthenticationException
{
    public function __construct(
        string $message = 'The new password cannot reuse the current password or a retained historical password.',
        string $reasonCode = 'auth.recovery.password_reuse',
    ) {
        parent::__construct($message, $reasonCode);
    }
}
