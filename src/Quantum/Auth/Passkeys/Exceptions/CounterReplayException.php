<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys\Exceptions;

final class CounterReplayException extends AssertionVerificationFailedException
{
    public static function forCounter(int $presented, int $stored, string $credentialId): self
    {
        return new self(sprintf(
            'Counter replay detected on credential %s: presented=%d stored=%d (strictly greater required)',
            $credentialId,
            $presented,
            $stored,
        ));
    }
}
