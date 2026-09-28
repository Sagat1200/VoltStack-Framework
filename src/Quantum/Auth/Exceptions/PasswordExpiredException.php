<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Auth\Exceptions\AuthenticationException;

class PasswordExpiredException extends AuthenticationException
{
    public function __construct(
        string $message = 'Password has expired and must be rotated before continuing.',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly int $passwordCreatedAt = 0,
        public readonly ?int $expiresAt = null,
        public readonly ?int $expiresAfterSeconds = null,
    ) {
        parent::__construct($message, 'auth.password_expired');
        if ($code !== 0 || $previous !== null) {
            // no-op: preserve optional params for signatures; parent does not accept $code/$previous via constructor directly in base;
            // use reflectionless approach — nothing needed, keep signature for callers who may pass them.
        }
    }
}
