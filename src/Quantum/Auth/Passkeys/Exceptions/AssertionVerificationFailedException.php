<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys\Exceptions;

class AssertionVerificationFailedException extends \RuntimeException
{
    public static function forReason(string $reason): self
    {
        return new self(sprintf('Passkey assertion verification failed: %s', $reason));
    }
}
