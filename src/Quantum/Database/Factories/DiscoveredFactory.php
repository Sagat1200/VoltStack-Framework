<?php

declare(strict_types=1);

namespace Quantum\Database\Factories;

use Quantum\Database\Contracts\FactoryInterface;

/**
 * Value-object describing a factory discovered on disk by `FactoryDiscovery`.
 *
 * Mirrors the shape used by `DiscoveredMigration` so discovery, runner and
 * feature test code share a consistent pattern.
 */
final readonly class DiscoveredFactory
{
    public function __construct(
        public string $name,
        public string $path,
        public FactoryInterface $instance,
    ) {
    }
}
