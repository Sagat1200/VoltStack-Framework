<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use RuntimeException;

final class RevokedAuthenticationSessionException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'auth.revoked_session',
        string $message = 'The authentication session has been revoked.',
    ) {
        parent::__construct($message);
    }
}
