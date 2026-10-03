<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Exception;

final class MissingProviderDependencyException extends BootstrapDependencyException
{
    public static function forRequirement(string $providerId, string $requiredId): self
    {
        return new self(sprintf(
            'Provider [%s] requires missing provider [%s].',
            $providerId,
            $requiredId,
        ));
    }
}
