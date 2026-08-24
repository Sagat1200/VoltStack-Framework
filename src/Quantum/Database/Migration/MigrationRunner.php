<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind;
use Quantum\Database\Dbal\Exception\DbalException;

final class MigrationRunner
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly MigrationDiscovery $discovery,
        private readonly MigrationRepository $repository,
        private readonly ?MigrationLock $lock = null,
    ) {}


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
        $plan = $this->planMigrate($step);

        if (!$plan->hasItems()) {
            return [
                'planned' => 0,
                'applied' => [],
                'pretended' => [],
                'plan' => $plan,
            ];
        }

        if ($pretend) {
            return [
                'planned' => $plan->plannedCount(),
                'applied' => [],
                'pretended' => $plan->versions(),
                'plan' => $plan,
            ];
        }

        $executed = $this->executePlan($plan);

        return [
            'planned' => $plan->plannedCount(),
            'applied' => $executed['applied'],
            'pretended' => [],
            'plan' => $plan,
        ];
    }

    public function planMigrate(?int $step = null): MigrationPlan
    {
        $all = $this->discovery->discover();
        $applied = $this->repository->appliedByVersion();
        $pending = [];

        foreach ($all as $migration) {
            if (!isset($applied[$migration->version()])) {
                $pending[] = $migration;
            }
        }

        if ($step !== null && $step > 0) {
            $pending = array_slice($pending, 0, $step);
        }

        return MigrationPlan::forMigrate(
            driver: $this->connection->getDriverInfo(),
            capabilities: $this->connection->getCapabilities(),
            repositoryTable: $this->repository->tableName(),
            batchNumber: $pending === [] ? null : $this->repository->nextBatchNumber(),
            stepLimit: $step,
            discoveredCount: count($all),
            appliedCount: count($applied),
            migrations: $pending,
        );
    }

    /**
     * @return array{
     *   planned:int,
     *   applied:list<string>,
     *   verification:MigrationVerificationResult
     * }
     */
    public function executePlan(MigrationPlan $plan): array
    {
        if ($plan->operation !== 'migrate') {
            throw new \RuntimeException(sprintf('Unsupported migration plan operation [%s].', $plan->operation));
        }

        return $this->withExecutionLock(function () use ($plan): array {
            if (!$plan->hasItems()) {
                return [
                    'planned' => 0,
                    'applied' => [],
                    'verification' => new MigrationVerificationResult(
                        verified: true,
                        fingerprint: $plan->fingerprint,
                        batchNumber: $plan->batchNumber,
                        verifiedVersions: [],
                        remainingPendingVersions: [],
                    ),
                ];
            }

            $batch = $plan->batchNumber ?? $this->repository->nextBatchNumber();
            $appliedVersions = [];

            try {
                foreach ($plan->items as $item) {
                    $this->runUp($item->migration, $batch);
                    $appliedVersions[] = $item->version();
                }
            } catch (\Throwable $e) {
                $failedItem = $this->resolveFailedPlanItem($plan, count($appliedVersions));

                throw $this->mapExecutionFailure(
                    throwable: $e,
                    plan: $plan,
                    batch: $batch,
                    appliedVersions: $appliedVersions,
                    failedItem: $failedItem,
                    phase: 'execute',
                );
            }

            try {
                $verification = $this->verifyExecutedPlan($plan, $appliedVersions, $batch);
            } catch (\Throwable $e) {
                throw $this->mapExecutionFailure(
                    throwable: $e,
                    plan: $plan,
                    batch: $batch,
                    appliedVersions: $appliedVersions,
                    failedItem: null,
                    phase: 'verify',
                );
            }

            return [
                'planned' => $plan->plannedCount(),
                'applied' => $appliedVersions,
                'verification' => $verification,
            ];
        });
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

        return $this->withExecutionLock(function () use ($target): array {
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
        });
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

    /**
     * @param list<string> $appliedVersions
     */
    private function verifyExecutedPlan(MigrationPlan $plan, array $appliedVersions, int $batch): MigrationVerificationResult
    {
        $applied = $this->repository->appliedByVersion();

        foreach ($appliedVersions as $version) {
            $record = $applied[$version] ?? null;

            if ($record === null) {
                throw new \RuntimeException(sprintf(
                    'Migration verification failed for fingerprint [%s]: version [%s] was executed but not recorded in [%s].',
                    $plan->fingerprint,
                    $version,
                    $this->repository->tableName(),
                ));
            }

            if ($record->batch !== $batch) {
                throw new \RuntimeException(sprintf(
                    'Migration verification failed for fingerprint [%s]: version [%s] was recorded in batch [%d] instead of [%d].',
                    $plan->fingerprint,
                    $version,
                    $record->batch,
                    $batch,
                ));
            }
        }

        $freshPlan = $this->planMigrate();
        $remainingPending = $freshPlan->versions();
        $overlap = array_values(array_intersect($appliedVersions, $remainingPending));

        if ($overlap !== []) {
            throw new \RuntimeException(sprintf(
                'Migration verification failed for fingerprint [%s]: executed version(s) still pending [%s].',
                $plan->fingerprint,
                implode(', ', $overlap),
            ));
        }

        return new MigrationVerificationResult(
            verified: true,
            fingerprint: $plan->fingerprint,
            batchNumber: $batch,
            verifiedVersions: $appliedVersions,
            remainingPendingVersions: $remainingPending,
        );
    }

    private function resolveFailedPlanItem(MigrationPlan $plan, int $completedCount): ?MigrationPlanItem
    {
        return $plan->items[$completedCount] ?? null;
    }

    /**
     * @param list<string> $appliedVersions
     */
    private function mapExecutionFailure(
        \Throwable $throwable,
        MigrationPlan $plan,
        ?int $batch,
        array $appliedVersions,
        ?MigrationPlanItem $failedItem,
        string $phase,
    ): MigrationExecutionException {
        if ($throwable instanceof MigrationExecutionException) {
            return $throwable;
        }

        $failure = MigrationOperationalFailure::Permanent;
        $retryable = false;

        if ($phase === 'verify') {
            $failure = MigrationOperationalFailure::VerificationFailed;
        } elseif ($throwable instanceof DbalException) {
            [$failure, $retryable] = $this->mapDbalFailure($throwable);
        }

        $checkpoint = new MigrationExecutionCheckpoint(
            fingerprint: $plan->fingerprint,
            batchNumber: $batch,
            phase: $phase,
            plannedCount: $plan->plannedCount(),
            failedPosition: count($appliedVersions) + 1,
            completedVersions: $appliedVersions,
            failedVersion: $failedItem?->version(),
            failedMigration: $failedItem?->migrationClass(),
            failedDescription: $failedItem?->description(),
        );

        $message = sprintf(
            'Migration %s failed [%s] at position %d/%d after %d completed step(s): %s',
            $phase,
            $failure->value,
            $checkpoint->failedPosition,
            $checkpoint->plannedCount,
            $checkpoint->completedCount(),
            $throwable->getMessage(),
        );

        return new MigrationExecutionException(
            failure: $failure,
            checkpoint: $checkpoint,
            retryable: $retryable,
            message: $message,
            previous: $throwable,
        );
    }

    /**
     * @return array{MigrationOperationalFailure,bool}
     */
    private function mapDbalFailure(DbalException $e): array
    {
        return match ($e->kind) {
            DatabaseFailureKind::Configuration,
            DatabaseFailureKind::Validation,
            DatabaseFailureKind::Capability => [MigrationOperationalFailure::InvalidPlan, false],
            DatabaseFailureKind::Authorization => [MigrationOperationalFailure::Unauthorized, false],
            DatabaseFailureKind::Timeout,
            DatabaseFailureKind::Concurrency,
            DatabaseFailureKind::Connectivity => [MigrationOperationalFailure::Transient, $e->retryable],
            DatabaseFailureKind::Integrity,
            DatabaseFailureKind::Internal => [MigrationOperationalFailure::Permanent, $e->retryable],
        };
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withExecutionLock(callable $callback): mixed
    {
        if ($this->lock === null) {
            return $callback();
        }

        $lease = $this->lock->acquire();

        try {
            return $callback();
        } finally {
            $lease->release();
        }
    }
}
