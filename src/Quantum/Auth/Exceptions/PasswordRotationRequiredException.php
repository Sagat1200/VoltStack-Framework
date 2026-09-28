<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Auth\Exceptions\AuthenticationException;

class PasswordRotationRequiredException extends AuthenticationException
{
    public function __construct(
        string $message = 'Password rotation is required before authentication can continue.',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly int $passwordCreatedAt = 0,
        public readonly int $rotationWindowSeconds = 0,
        public readonly int $ageSeconds = 0,
    ) {
        parent::__construct($message, 'auth.password_rotation_required');
    }
}
