<?php

declare(strict_types=1);

namespace Quantum\Database\Seeders;

use Quantum\Database\Contracts\SeederInterface;

/**
 * Value-object describing a seeder discovered on disk by `SeederDiscovery`.
 *
 * Mirrors `DiscoveredMigration` / `DiscoveredFactory` for consistency.
 */
final readonly class DiscoveredSeeder
{
    public function __construct(
        public string $name,
        public string $path,
        public SeederInterface $instance,
    ) {
    }
}
