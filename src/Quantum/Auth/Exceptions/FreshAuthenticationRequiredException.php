<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use RuntimeException;

final class FreshAuthenticationRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $operation = 'session_management',
        public readonly int $freshWindowSeconds = 300,
        public readonly string $reasonCode = 'auth.fresh_authentication_required',
        string $message = 'Fresh authentication is required for this operation.',
    ) {
        parent::__construct($message);
    }
}
