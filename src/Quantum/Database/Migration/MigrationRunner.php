<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Contracts\ConnectionManagerInterface;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private readonly MigrationDiscovery $discovery,
        private readonly MigrationRepository $repository,
        private readonly \Quantum\Database\Schema\SchemaManager $schema,
        private readonly ConnectionManagerInterface $connections,
    ) {
    }

    public function migrate(?string $path = null): int
    {
        $this->repository->ensureRepository();

        $discovered = $this->discovery->discover($path);
        $applied = array_flip($this->repository->appliedNames());
        $batch = $this->repository->nextBatchNumber();
        $count = 0;

        foreach ($discovered as $migration) {
            if (isset($applied[$migration->name])) {
                continue;
            }

            $migration->instance->up($this->schema);
            $this->repository->logApplied($migration->name, $batch);
            $count++;
        }

        $this->connections->disconnectAll();

        return $count;
    }

    public function rollbackLastBatch(?string $path = null): int
    {
        $this->repository->ensureRepository();

        $available = [];

        foreach ($this->discovery->discover($path) as $migration) {
            $available[$migration->name] = $migration;
        }

        $count = 0;

        foreach ($this->repository->lastBatchMigrationNames() as $migrationName) {
            $migration = $available[$migrationName] ?? null;

            if ($migration === null) {
                throw new RuntimeException(sprintf('Unable to rollback migration [%s] because the file is missing.', $migrationName));
            }

            $migration->instance->down($this->schema);
            $this->repository->remove($migrationName);
            $count++;
        }

        $this->connections->disconnectAll();

        return $count;
    }
}
