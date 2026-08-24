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
        private readonly ?MigrationRecoveryStore $recoveryStore = null,
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
     * @return array{planned:int,applied:list<string>,pretended:list<string>,plan:MigrationPlan}
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
                $this->clearRecoveryState();

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

            $this->clearRecoveryState();

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

        $target = $this->resolveRollbackTargetVersions(array_map(
            static fn(MigrationRecord $record): string => $record->version,
            $records,
        ));

        if ($pretend) {
            return [
                'planned' => count($target),
                'rolled_back' => [],
                'pretended' => array_map(static fn(MigrationInterface $migration): string => $migration->version(), $target),
            ];
        }

        return $this->executeRollbackMigrations($target, $this->rollbackFingerprint($target, $step));
    }

    public function recoveryState(): ?MigrationRecoveryState
    {
        return $this->recoveryStore?->load();
    }

    public function planRecovery(?string $strategy = null): ?MigrationRecoveryPlan
    {
        $state = $this->recoveryState();
        if ($state === null) {
            return null;
        }

        return $this->buildRecoveryPlan($state, $strategy);
    }

    /**
     * @return array{
     *   action:string,
     *   planned:int,
     *   fingerprint:string,
     *   applied:list<string>,
     *   rolled_back:list<string>,
     *   reconciled:list<string>,
     *   pretended:list<string>,
     *   verification:MigrationVerificationResult|null
     * }
     */
    public function recover(bool $pretend = false, ?string $strategy = null): array
    {
        $plan = $this->planRecovery($strategy);
        if ($plan === null) {
            throw new \RuntimeException('No migration recovery plan is currently recorded.');
        }

        if ($pretend) {
            return [
                'action' => $plan->action,
                'planned' => $plan->plannedCount(),
                'fingerprint' => $plan->checkpoint->fingerprint,
                'applied' => [],
                'rolled_back' => [],
                'reconciled' => [],
                'pretended' => $plan->versions,
                'verification' => null,
            ];
        }

        return match ($plan->action) {
            'resume_migrate' => $this->executeRecoveryMigratePlan($plan),
            'rollback_partial', 'continue_rollback' => $this->executeRecoveryRollbackPlan($plan),
            'reconcile_status' => $this->executeRecoveryReconciliation($plan),
            default => throw new \RuntimeException(sprintf('Unsupported migration recovery action [%s].', $plan->action)),
        };
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

        $this->persistRecoveryState(new MigrationRecoveryState(
            operation: 'migrate',
            checkpoint: $checkpoint,
            targetVersions: $plan->versions(),
            recordedAt: $this->timestampNow(),
        ));

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
     * @param list<string> $rolledBackVersions
     */
    private function mapRollbackFailure(
        \Throwable $throwable,
        string $fingerprint,
        array $rolledBackVersions,
        ?MigrationInterface $failedMigration,
        int $plannedCount,
        array $targetVersions,
    ): MigrationExecutionException {
        if ($throwable instanceof MigrationExecutionException) {
            return $throwable;
        }

        [$failure, $retryable] = $throwable instanceof DbalException
            ? $this->mapDbalFailure($throwable)
            : [MigrationOperationalFailure::Permanent, false];

        $checkpoint = new MigrationExecutionCheckpoint(
            fingerprint: $fingerprint,
            batchNumber: null,
            phase: 'rollback',
            plannedCount: $plannedCount,
            failedPosition: count($rolledBackVersions) + 1,
            completedVersions: $rolledBackVersions,
            failedVersion: $failedMigration?->version(),
            failedMigration: $failedMigration !== null ? $failedMigration::class : null,
            failedDescription: $failedMigration?->description(),
        );

        $this->persistRecoveryState(new MigrationRecoveryState(
            operation: 'rollback',
            checkpoint: $checkpoint,
            targetVersions: array_values($targetVersions),
            recordedAt: $this->timestampNow(),
        ));

        $message = sprintf(
            'Migration rollback failed [%s] at position %d/%d after %d completed step(s): %s',
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
     * @param list<MigrationInterface> $target
     */
    private function rollbackFingerprint(array $target, ?int $step): string
    {
        $driver = $this->connection->getDriverInfo();
        $payload = [
            'format' => 'voltstack-migration-rollback-v1',
            'operation' => 'rollback',
            'repository_table' => $this->repository->tableName(),
            'step_limit' => $step,
            'driver' => [
                'name' => $driver->driverName,
                'server_version' => $driver->serverVersion,
                'database' => $driver->databaseName,
                'charset' => $driver->charset,
            ],
            'items' => array_map(
                static fn(MigrationInterface $migration): array => [
                    'version' => $migration->version(),
                    'migration' => $migration::class,
                    'description' => $migration->description(),
                    'transactional' => $migration->isTransactional(),
                ],
                $target,
            ),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
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

    private function buildRecoveryPlan(MigrationRecoveryState $state, ?string $strategy): MigrationRecoveryPlan
    {
        $selectedStrategy = $this->normalizeRecoveryStrategy($strategy);
        $applied = $this->repository->appliedByVersion();

        if ($state->checkpoint->phase === 'verify') {
            return new MigrationRecoveryPlan(
                action: 'reconcile_status',
                sourceOperation: $state->operation,
                checkpoint: $state->checkpoint,
                targetVersions: $state->targetVersions,
                versions: $state->targetVersions,
                summary: 'Verifica y reconcilia el estado del historial antes de continuar con nuevas migraciones.',
            );
        }

        if ($state->operation === 'migrate') {
            if ($selectedStrategy === 'rollback-partial') {
                $versions = array_values(array_reverse($state->checkpoint->completedVersions));

                return new MigrationRecoveryPlan(
                    action: 'rollback_partial',
                    sourceOperation: 'migrate',
                    checkpoint: $state->checkpoint,
                    targetVersions: $state->targetVersions,
                    versions: $versions,
                    summary: 'Revierte solo las migraciones aplicadas por la ejecucion fallida para volver al punto inicial del plan.',
                );
            }

            $versions = [];
            foreach ($state->targetVersions as $version) {
                if (!isset($applied[$version])) {
                    $versions[] = $version;
                }
            }

            if ($versions === [] || $selectedStrategy === 'reconcile') {
                return new MigrationRecoveryPlan(
                    action: 'reconcile_status',
                    sourceOperation: 'migrate',
                    checkpoint: $state->checkpoint,
                    targetVersions: $state->targetVersions,
                    versions: $state->targetVersions,
                    summary: 'Confirma si el historial ya refleja el plan original y limpia el recovery pendiente.',
                );
            }

            return new MigrationRecoveryPlan(
                action: 'resume_migrate',
                sourceOperation: 'migrate',
                checkpoint: $state->checkpoint,
                targetVersions: $state->targetVersions,
                versions: $versions,
                summary: 'Reanuda el plan original ejecutando solo las migraciones que siguen pendientes.',
            );
        }

        $versions = [];
        foreach ($state->targetVersions as $version) {
            if (isset($applied[$version])) {
                $versions[] = $version;
            }
        }

        if ($versions === [] || $selectedStrategy === 'reconcile') {
            return new MigrationRecoveryPlan(
                action: 'reconcile_status',
                sourceOperation: 'rollback',
                checkpoint: $state->checkpoint,
                targetVersions: $state->targetVersions,
                versions: $state->targetVersions,
                summary: 'Confirma si el rollback objetivo ya quedo reconciliado y limpia el recovery pendiente.',
            );
        }

        return new MigrationRecoveryPlan(
            action: 'continue_rollback',
            sourceOperation: 'rollback',
            checkpoint: $state->checkpoint,
            targetVersions: $state->targetVersions,
            versions: $versions,
            summary: 'Continua el rollback original usando las migraciones objetivo que siguen aplicadas.',
        );
    }

    private function normalizeRecoveryStrategy(?string $strategy): string
    {
        $normalized = strtolower(trim((string) $strategy));
        if ($normalized === '' || $normalized === 'auto') {
            return 'auto';
        }

        if (!in_array($normalized, ['resume', 'rollback-partial', 'continue', 'reconcile'], true)) {
            throw new \RuntimeException(sprintf('Unsupported migration recovery strategy [%s].', $strategy));
        }

        return $normalized;
    }

    /**
     * @return array<string, MigrationInterface>
     */
    private function discoveryMap(): array
    {
        $all = [];

        foreach ($this->discovery->discover() as $migration) {
            $all[$migration->version()] = $migration;
        }

        return $all;
    }

    /**
     * @param list<string> $versions
     * @return list<MigrationInterface>
     */
    private function resolveRollbackTargetVersions(array $versions): array
    {
        $all = $this->discoveryMap();
        $target = [];

        foreach ($versions as $version) {
            if (!isset($all[$version])) {
                throw new \RuntimeException(sprintf(
                    'Applied migration version [%s] is missing from discovery and cannot be rolled back.',
                    $version,
                ));
            }

            $target[] = $all[$version];
        }

        return $target;
    }

    private function buildMigratePlanFromVersions(array $versions, ?int $batchNumber): MigrationPlan
    {
        $all = $this->discoveryMap();
        $selected = [];

        foreach ($versions as $version) {
            if (!isset($all[$version])) {
                throw new \RuntimeException(sprintf(
                    'Migration version [%s] is missing from discovery and cannot be resumed.',
                    $version,
                ));
            }

            $selected[] = $all[$version];
        }

        return MigrationPlan::forMigrate(
            driver: $this->connection->getDriverInfo(),
            capabilities: $this->connection->getCapabilities(),
            repositoryTable: $this->repository->tableName(),
            batchNumber: $selected === [] ? null : $batchNumber,
            stepLimit: null,
            discoveredCount: count($all),
            appliedCount: count($this->repository->appliedByVersion()),
            migrations: $selected,
        );
    }

    /**
     * @param list<MigrationInterface> $target
     * @return array{planned:int,rolled_back:list<string>,pretended:list<string>}
     */
    private function executeRollbackMigrations(array $target, ?string $fingerprint): array
    {
        return $this->withExecutionLock(function () use ($target, $fingerprint): array {
            $rolledBack = [];
            $effectiveFingerprint = $fingerprint ?? $this->rollbackFingerprint($target, null);
            $targetVersions = array_map(static fn(MigrationInterface $migration): string => $migration->version(), $target);

            try {
                foreach ($target as $migration) {
                    $this->runDown($migration);
                    $rolledBack[] = $migration->version();
                }
            } catch (\Throwable $e) {
                $failedMigration = $target[count($rolledBack)] ?? null;

                throw $this->mapRollbackFailure(
                    throwable: $e,
                    fingerprint: $effectiveFingerprint,
                    rolledBackVersions: $rolledBack,
                    failedMigration: $failedMigration,
                    plannedCount: count($target),
                    targetVersions: $targetVersions,
                );
            }

            $this->clearRecoveryState();

            return [
                'planned' => count($target),
                'rolled_back' => $rolledBack,
                'pretended' => [],
            ];
        });
    }

    /**
     * @return array{
     *   action:string,
     *   planned:int,
     *   fingerprint:string,
     *   applied:list<string>,
     *   rolled_back:list<string>,
     *   reconciled:list<string>,
     *   pretended:list<string>,
     *   verification:MigrationVerificationResult|null
     * }
     */
    private function executeRecoveryMigratePlan(MigrationRecoveryPlan $plan): array
    {
        $migratePlan = $this->buildMigratePlanFromVersions($plan->versions, $plan->checkpoint->batchNumber);
        $result = $this->executePlan($migratePlan);

        return [
            'action' => $plan->action,
            'planned' => $migratePlan->plannedCount(),
            'fingerprint' => $migratePlan->fingerprint,
            'applied' => $result['applied'],
            'rolled_back' => [],
            'reconciled' => [],
            'pretended' => [],
            'verification' => $result['verification'],
        ];
    }

    /**
     * @return array{
     *   action:string,
     *   planned:int,
     *   fingerprint:string,
     *   applied:list<string>,
     *   rolled_back:list<string>,
     *   reconciled:list<string>,
     *   pretended:list<string>,
     *   verification:MigrationVerificationResult|null
     * }
     */
    private function executeRecoveryRollbackPlan(MigrationRecoveryPlan $plan): array
    {
        $target = $this->resolveRollbackTargetVersions($plan->versions);
        $result = $this->executeRollbackMigrations($target, $plan->checkpoint->fingerprint);

        return [
            'action' => $plan->action,
            'planned' => $result['planned'],
            'fingerprint' => $plan->checkpoint->fingerprint,
            'applied' => [],
            'rolled_back' => $result['rolled_back'],
            'reconciled' => [],
            'pretended' => [],
            'verification' => null,
        ];
    }

    /**
     * @return array{
     *   action:string,
     *   planned:int,
     *   fingerprint:string,
     *   applied:list<string>,
     *   rolled_back:list<string>,
     *   reconciled:list<string>,
     *   pretended:list<string>,
     *   verification:MigrationVerificationResult|null
     * }
     */
    private function executeRecoveryReconciliation(MigrationRecoveryPlan $plan): array
    {
        $applied = $this->repository->appliedByVersion();

        if ($plan->sourceOperation === 'migrate') {
            $missing = [];

            foreach ($plan->targetVersions as $version) {
                if (!isset($applied[$version])) {
                    $missing[] = $version;
                }
            }

            if ($missing !== []) {
                throw new \RuntimeException(sprintf(
                    'Cannot reconcile migration recovery because version(s) are still missing from history: [%s].',
                    implode(', ', $missing),
                ));
            }
        } else {
            $stillApplied = [];

            foreach ($plan->targetVersions as $version) {
                if (isset($applied[$version])) {
                    $stillApplied[] = $version;
                }
            }

            if ($stillApplied !== []) {
                throw new \RuntimeException(sprintf(
                    'Cannot reconcile rollback recovery because version(s) are still applied: [%s].',
                    implode(', ', $stillApplied),
                ));
            }
        }

        $this->clearRecoveryState();

        return [
            'action' => $plan->action,
            'planned' => count($plan->targetVersions),
            'fingerprint' => $plan->checkpoint->fingerprint,
            'applied' => [],
            'rolled_back' => [],
            'reconciled' => $plan->targetVersions,
            'pretended' => [],
            'verification' => null,
        ];
    }

    private function persistRecoveryState(MigrationRecoveryState $state): void
    {
        $this->recoveryStore?->save($state);
    }

    private function clearRecoveryState(): void
    {
        $this->recoveryStore?->clear();
    }

    private function timestampNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
