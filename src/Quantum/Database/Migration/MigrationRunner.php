<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Telemetry\DatabaseTelemetryEmitter;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private readonly MigrationDiscovery $discovery,
        private readonly MigrationRepository $repository,
        private readonly \Quantum\Database\Schema\SchemaManager $schema,
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseTelemetryEmitter $telemetry,
    ) {
    }

    public function migrate(?string $path = null, ?string $connectionName = null): int
    {
        $repository = $connectionName === null
            ? $this->repository
            : $this->repository->connection($connectionName);
        $schema = $connectionName === null
            ? $this->schema
            : $this->schema->connection($connectionName);

        $repository->ensureRepository();

        $discovered = $this->discovery->discover($path);
        $applied = array_flip($repository->appliedNames());
        $batch = $repository->nextBatchNumber();
        $count = 0;

        foreach ($discovered as $migration) {
            if (isset($applied[$migration->name])) {
                continue;
            }

            $migration->instance->up($schema);
            $repository->logApplied($migration->name, $batch);
            $count++;
        }

        $this->connections->disconnectAll();
        $this->telemetry->migrationsCompleted('migrated', $count, $path, $connectionName);

        return $count;
    }

    public function rollbackLastBatch(?string $path = null, ?string $connectionName = null): int
    {
        $repository = $connectionName === null
            ? $this->repository
            : $this->repository->connection($connectionName);
        $schema = $connectionName === null
            ? $this->schema
            : $this->schema->connection($connectionName);

        $repository->ensureRepository();

        $available = [];

        foreach ($this->discovery->discover($path) as $migration) {
            $available[$migration->name] = $migration;
        }

        $count = 0;

        foreach ($repository->lastBatchMigrationNames() as $migrationName) {
            $migration = $available[$migrationName] ?? null;

            if ($migration === null) {
                throw new RuntimeException(sprintf('Unable to rollback migration [%s] because the file is missing.', $migrationName));
            }

            $migration->instance->down($schema);
            $repository->remove($migrationName);
            $count++;
        }

        $this->connections->disconnectAll();
        $this->telemetry->migrationsCompleted('rolled_back', $count, $path, $connectionName);

        return $count;
    }
}
