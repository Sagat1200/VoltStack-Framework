<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use RuntimeException;

final class StaleAuthenticationSessionException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'auth.stale_session',
        string $message = 'The authentication session is stale or invalid.',
    ) {
        parent::__construct($message);
    }
}