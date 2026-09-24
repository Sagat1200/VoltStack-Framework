<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final readonly class DiscoveredMigration
{
    public function __construct(
        public string $name,
        public string $path,
        public MigrationInterface $instance,
    ) {
    }
}
