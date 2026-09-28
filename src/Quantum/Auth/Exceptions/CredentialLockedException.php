<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Auth\Exceptions\AuthenticationException;

class CredentialLockedException extends AuthenticationException
{
    public function __construct(
        string $message = 'Credential is temporarily locked due to repeated failed authentication attempts.',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?int $lockoutUntil = null,
        public readonly int $failedAttempts = 0,
    ) {
        parent::__construct($message, 'auth.credential_locked');
    }
}
