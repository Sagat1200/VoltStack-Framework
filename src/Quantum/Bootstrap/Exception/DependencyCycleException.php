<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Exception;

final class DependencyCycleException extends BootstrapDependencyException
{
    /**
     * @param list<string> $providerIds
     */
    public static function forProviders(array $providerIds): self
    {
        return new self(sprintf(
            'Provider dependency cycle detected: %s',
            implode(' -> ', $providerIds),
        ));
    }
}
