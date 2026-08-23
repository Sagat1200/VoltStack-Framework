<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

final class MigrationRunner
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly MigrationDiscovery $discovery,
        private readonly MigrationRepository $repository,
    ) {
    }

    /**
     * @return list<array{
     *   version:string,
     *   migration:string,
     *   description:string,
     *   applied:bool,
     *   batch:int|null,
     *   executed_at:string|null
     * }>
     */
    public function status(): array
    {
        $applied = $this->repository->appliedByVersion();
        $status = [];

        foreach ($this->discovery->discover() as $migration) {
            $record = $applied[$migration->version()] ?? null;
            $status[] = [
                'version' => $migration->version(),
                'migration' => $migration::class,
                'description' => $migration->description(),
                'applied' => $record !== null,
                'batch' => $record?->batch,
                'executed_at' => $record?->executedAt,
            ];
        }

        return $status;
    }

    /**
     * @return array{planned:int,applied:list<string>,pretended:list<string>}
     */
    public function migrate(bool $pretend = false, ?int $step = null): array
    {
        $applied = $this->repository->appliedByVersion();
        $pending = [];

        foreach ($this->discovery->discover() as $migration) {
            if (!isset($applied[$migration->version()])) {
                $pending[] = $migration;
            }
        }

        if ($step !== null && $step > 0) {
            $pending = array_slice($pending, 0, $step);
        }

        if ($pending === []) {
            return [
                'planned' => 0,
                'applied' => [],
                'pretended' => [],
            ];
        }

        if ($pretend) {
            return [
                'planned' => count($pending),
                'applied' => [],
                'pretended' => array_map(static fn(MigrationInterface $migration): string => $migration->version(), $pending),
            ];
        }

        $batch = $this->repository->nextBatchNumber();
        $appliedVersions = [];

        foreach ($pending as $migration) {
            $this->runUp($migration, $batch);
            $appliedVersions[] = $migration->version();
        }

        return [
            'planned' => count($pending),
            'applied' => $appliedVersions,
            'pretended' => [],
        ];
    }

    /**
     * @return array{planned:int,rolled_back:list<string>,pretended:list<string>}
     */
    public function rollback(bool $pretend = false, ?int $step = null): array
    {
        $records = $step !== null && $step > 0
            ? $this->repository->latest($step)
            : $this->repository->latestBatch();

        if ($records === []) {
            return [
                'planned' => 0,
                'rolled_back' => [],
                'pretended' => [],
            ];
        }

        $all = [];
        foreach ($this->discovery->discover() as $migration) {
            $all[$migration->version()] = $migration;
        }

        $target = [];
        foreach ($records as $record) {
            if (!isset($all[$record->version])) {
                throw new \RuntimeException(sprintf(
                    'Applied migration version [%s] is missing from discovery and cannot be rolled back.',
                    $record->version,
                ));
            }

            $target[] = $all[$record->version];
        }

        if ($pretend) {
            return [
                'planned' => count($target),
                'rolled_back' => [],
                'pretended' => array_map(static fn(MigrationInterface $migration): string => $migration->version(), $target),
            ];
        }

        $rolledBack = [];

        foreach ($target as $migration) {
            $this->runDown($migration);
            $rolledBack[] = $migration->version();
        }

        return [
            'planned' => count($target),
            'rolled_back' => $rolledBack,
            'pretended' => [],
        ];
    }

    private function runUp(MigrationInterface $migration, int $batch): void
    {
        $this->wrapTransactional($migration, function () use ($migration, $batch): void {
            $migration->up($this->connection);
            $this->repository->recordApplied($migration, $batch);
        });
    }

    private function runDown(MigrationInterface $migration): void
    {
        $this->wrapTransactional($migration, function () use ($migration): void {
            $migration->down($this->connection);
            $this->repository->remove($migration);
        });
    }

    private function wrapTransactional(MigrationInterface $migration, callable $callback): void
    {
        $useTransaction = $migration->isTransactional() && !$this->connection->inTransaction();

        if (!$useTransaction) {
            $callback();
            return;
        }

        $this->connection->beginTransaction();

        try {
            $callback();
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollback();
            }

            throw $e;
        }
    }
}
