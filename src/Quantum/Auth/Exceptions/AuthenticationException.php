<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use RuntimeException;

class AuthenticationException extends RuntimeException
{
    public function __construct(
        string $message = 'Authentication failed.',
        public readonly string $reasonCode = 'auth.failed',
    ) {
        parent::__construct($message);
    }
}
