<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Exception;

final class DuplicateProviderIdException extends BootstrapDependencyException
{
    public static function forId(string $providerId): self
    {
        return new self(sprintf(
            'Duplicate provider metadata id detected: [%s].',
            $providerId,
        ));
    }
}
