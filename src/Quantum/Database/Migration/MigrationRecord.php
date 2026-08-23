<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final class MigrationRecord
{
    public function __construct(
        public readonly string $version,
        public readonly string $migration,
        public readonly int $batch,
        public readonly string $executedAt,
    ) {
    }
}
