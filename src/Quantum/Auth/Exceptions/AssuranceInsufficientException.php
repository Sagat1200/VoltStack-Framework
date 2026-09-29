<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

class AssuranceInsufficientException extends AuthenticationException
{
    public function __construct(
        string $message = 'Your current authentication assurance level is insufficient to complete this operation.',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly int $requiredMinAssurance = 0,
        public readonly int $currentAssurance = 0,
        public readonly ?string $operation = null,
    ) {
        parent::__construct($message, 'auth.assurance_insufficient');
    }
}
