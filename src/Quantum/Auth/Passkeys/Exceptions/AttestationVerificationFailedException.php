<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys\Exceptions;

final class AttestationVerificationFailedException extends \RuntimeException
{
    public static function forReason(string $reason): self
    {
        return new self(sprintf('Passkey attestation verification failed: %s', $reason));
    }
}
