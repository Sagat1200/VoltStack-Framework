<?php

declare(strict_types=1);

namespace Quantum\Database\Support;

final readonly class DatabaseStatus
{
    public function __construct(
        public string $defaultConnectionName,
        public string $connectionName,
        public string $driver,
        public string $platform,
        public string $database,
        public bool $telemetryEnabled,
        public bool $migrationRepositoryExists,
        public int $appliedMigrations,
        public bool $connected,
    ) {
    }
}
