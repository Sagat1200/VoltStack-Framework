<?php

declare(strict_types=1);

namespace Quantum\Database\Connection;

use Quantum\Database\Contracts\ConnectionInterface;
use Quantum\Database\Dialect\DialectResolver;
use Quantum\Database\Driver\DriverRegistry;
use Quantum\Database\Platform\PlatformResolver;

final class ConnectionFactory
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly PlatformResolver $platforms,
        private readonly DialectResolver $dialects,
    ) {
    }

    public function create(ConnectionDefinition $definition): ConnectionInterface
    {
        return new Connection(
            definition: $definition,
            driverInstance: $this->drivers->resolve($definition->driver),
            platformInstance: $this->platforms->resolve($definition),
            dialectInstance: $this->dialects->resolve($definition),
        );
    }
}
