<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Exception;

final class ProviderConflictException extends BootstrapDependencyException
{
    public static function between(string $providerId, string $conflictingId): self
    {
        return new self(sprintf(
            'Provider [%s] conflicts with provider [%s].',
            $providerId,
            $conflictingId,
        ));
    }
}
