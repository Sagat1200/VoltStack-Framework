<?php

declare(strict_types=1);

namespace Quantum\Database\Platform;

use Quantum\Database\Connection\ConnectionDefinition;
use Quantum\Database\Contracts\PlatformInterface;

final class PlatformResolver
{
    public function resolve(ConnectionDefinition $definition): PlatformInterface
    {
        return match ($definition->platformId()) {
            'sqlite' => new SqlitePlatform(),
            default => new GenericPlatform($definition->platformId()),
        };
    }
}
