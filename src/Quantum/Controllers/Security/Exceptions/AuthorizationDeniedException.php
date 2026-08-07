<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Exceptions;

class AuthorizationDeniedException extends SecurityException
{
    public function __construct(
        public readonly string $reasonCode = 'deny_by_default',
        public readonly array $safeContext = [],
        string $message = 'Authorization denied',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'controller.security.authorization_denied';
    }
}
