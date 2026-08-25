<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Trace\DatabaseDeadline;

final class DatabaseOperationRuntime
{
    public function __construct(
        private readonly DatabaseCircuitBreaker $circuitBreaker,
        private readonly DatabaseTelemetryStore|\Closure|null $telemetry = null,
        private readonly DatabaseHealthStoreInterface|\Closure|null $healthStore = null,
        private readonly DatabaseIdempotencyStoreInterface|\Closure|null $idempotencyStore = null,
        private readonly string|\Closure|null $idempotencyNodeId = null,
    ) {}

    public function plan(RawOperation $operation, DatabaseContext $context, DatabaseExecutionPolicy $policy): DatabaseOperationPlan
    {
        $connection = $this->requireConnection($context);
        $connection->connect();

        $connectionName = $this->resolveConnectionName($operation, $context);
        $driver = $connection->getDriverInfo()->driverName;
        $safePreview = $this->safeSqlPreview($operation->sql);
        $sqlFingerprint = hash('sha256', $safePreview);
        $logicalTarget = $this->extractLogicalTarget($operation->sql);
        $deadline = $context->deadline ?? DatabaseDeadline::fromMs($policy->timeoutMs);
        $depth = $this->detectDepth($operation->sql);
        $retryable = $this->isRetryableOperation($operation, $policy);
        $retryLimit = $retryable ? $policy->retryLimit : 0;
        $idempotencyKeyHash = $this->resolveIdempotencyKeyHash($operation);
        $circuitSegment = $this->segmentKey(
            connectionName: $connectionName,
            driver: $driver,
            operationKind: $operation->kind->value,
            logicalTarget: $logicalTarget,
        );
        $payload = [
            'kind' => $operation->kind->value,
            'connection' => $connectionName,
            'driver' => $driver,
            'logical_target' => $logicalTarget,
            'sql_fingerprint' => $sqlFingerprint,
            'max_rows' => $policy->maxRows,
            'max_depth' => $policy->maxDepth,
            'retry_limit' => $retryLimit,
            'retry_mutations_when_idempotent' => $policy->retryMutationsWhenIdempotent,
            'idempotency_pending_ttl_seconds' => $policy->idempotencyPendingTtlSeconds,
            'idempotency_key_hash' => $idempotencyKeyHash,
            'tenant' => $context->tenantId,
            'request_id' => $context->requestId,
        ];

        return new DatabaseOperationPlan(
            operation: $operation,
            connectionName: $connectionName,
            driver: $driver,
            logicalTarget: $logicalTarget,
            circuitSegment: $circuitSegment,
            fingerprint: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            sqlFingerprint: $sqlFingerprint,
            safeSqlPreview: $safePreview,
            maxRows: $policy->maxRows,
            maxDepth: $policy->maxDepth,
            detectedDepth: $depth,
            retryLimit: $retryLimit,
            retryable: $retryable,
            deadline: $deadline,
            policy: $policy,
        );
    }

    public function execute(DatabaseOperationPlan $plan, DatabaseContext $context): DatabaseOperationResult
    {
        $connection = $this->requireConnection($context);
        $events = [
            new DatabaseDiagnosticEvent('planned', $this->timestampNow(), [
                'fingerprint' => $plan->fingerprint,
                'kind' => $plan->operation->kind->value,
            ]),
        ];
        $fallbackDecision = $this->resolveFallbackDecision($plan);
        if ($fallbackDecision !== null) {
            $snapshot = $this->snapshot(
                plan: $plan,
                attempts: 0,
                durationMs: 0,
                rowsRead: 0,
                affectedRows: 0,
                outcome: 'cancelled',
                failure: DatabaseOperationalFailure::Degraded,
                retryable: false,
                circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                events: array_merge($events, [
                    new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), [
                        'reason' => 'fallback_policy_degraded',
                        'mode' => $plan->policy->fallbackMode,
                        'aggregate' => $fallbackDecision,
                    ]),
                ]),
            );
            $this->recordTelemetry($plan, $snapshot);

            throw new DatabaseOperationException(
                failure: DatabaseOperationalFailure::Degraded,
                snapshot: $snapshot,
                plan: $plan,
                message: sprintf(
                    'Database fallback policy [%s] blocked [%s] because aggregate health is degraded (open=%d, half_open=%d).',
                    $plan->policy->fallbackMode,
                    $plan->operation->kind->value,
                    (int) ($fallbackDecision['health']['open_segments'] ?? 0),
                    (int) ($fallbackDecision['health']['half_open_segments'] ?? 0),
                ),
            );
        }

        if ($plan->detectedDepth > $plan->maxDepth) {
            $snapshot = $this->snapshot(
                plan: $plan,
                attempts: 0,
                durationMs: 0,
                rowsRead: 0,
                affectedRows: 0,
                outcome: 'failed',
                failure: DatabaseOperationalFailure::InvalidPlan,
                retryable: false,
                circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                events: array_merge($events, [
                    new DatabaseDiagnosticEvent('failed', $this->timestampNow(), [
                        'reason' => 'query_depth_exceeded',
                    ]),
                ]),
            );
            $this->recordTelemetry($plan, $snapshot);

            throw new DatabaseOperationException(
                failure: DatabaseOperationalFailure::InvalidPlan,
                snapshot: $snapshot,
                plan: $plan,
                message: sprintf('Operation depth [%d] exceeds configured max depth [%d].', $plan->detectedDepth, $plan->maxDepth),
            );
        }

        $idempotencyRecord = $this->buildIdempotencyRecord($plan, $context);
        if ($idempotencyRecord instanceof DatabaseIdempotencyRecord) {
            $acquire = $this->resolveIdempotencyStore()?->acquire($idempotencyRecord);
            if ($acquire instanceof DatabaseIdempotencyAcquireResult && !$acquire->acquired) {
                $snapshot = $this->snapshot(
                    plan: $plan,
                    attempts: 0,
                    durationMs: 0,
                    rowsRead: 0,
                    affectedRows: 0,
                    outcome: 'cancelled',
                    failure: DatabaseOperationalFailure::Duplicate,
                    retryable: false,
                    circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                    events: array_merge($events, [
                        new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), [
                            'reason' => 'idempotency_guard_' . $acquire->reason,
                            'status' => $acquire->record?->status,
                        ]),
                    ]),
                );
                $this->recordTelemetry($plan, $snapshot);

                throw new DatabaseOperationException(
                    failure: DatabaseOperationalFailure::Duplicate,
                    snapshot: $snapshot,
                    plan: $plan,
                    message: sprintf(
                        'Database idempotency guard blocked [%s] because key hash [%s] is already reserved with status [%s].',
                        $plan->operation->kind->value,
                        $idempotencyRecord->keyHash,
                        $acquire->record?->status ?? 'unknown',
                    ),
                );
            }
        }

        $segment = $plan->circuitSegment;
        $attempt = 0;
        $startedAt = hrtime(true);

        while (true) {
            if ($plan->deadline->isExpired()) {
                $snapshot = $this->snapshot(
                    plan: $plan,
                    attempts: $attempt,
                    durationMs: $this->durationMs($startedAt),
                    rowsRead: 0,
                    affectedRows: 0,
                    outcome: 'cancelled',
                    failure: DatabaseOperationalFailure::ResourceExhausted,
                    retryable: false,
                    circuitState: $this->circuitBreaker->currentState($segment),
                    events: array_merge($events, [
                        new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), [
                            'reason' => 'deadline_exhausted',
                        ]),
                    ]),
                );
                $this->recordTelemetry($plan, $snapshot);
                if ($idempotencyRecord instanceof DatabaseIdempotencyRecord) {
                    $this->resolveIdempotencyStore()?->release($idempotencyRecord);
                }

                throw new DatabaseOperationException(
                    failure: DatabaseOperationalFailure::ResourceExhausted,
                    snapshot: $snapshot,
                    plan: $plan,
                    message: 'Database operation deadline was exhausted before execution completed.',
                );
            }

            $gateState = $this->circuitBreaker->assertCanPass($segment, $plan->policy->circuitCooldownMs);
            if ($gateState === 'open') {
                $snapshot = $this->snapshot(
                    plan: $plan,
                    attempts: $attempt,
                    durationMs: $this->durationMs($startedAt),
                    rowsRead: 0,
                    affectedRows: 0,
                    outcome: 'failed',
                    failure: DatabaseOperationalFailure::Transient,
                    retryable: $plan->retryable,
                    circuitState: $gateState,
                    events: array_merge($events, [
                        new DatabaseDiagnosticEvent('failed', $this->timestampNow(), [
                            'reason' => 'circuit_open',
                        ]),
                    ]),
                );
                $this->recordTelemetry($plan, $snapshot);
                if ($idempotencyRecord instanceof DatabaseIdempotencyRecord) {
                    $this->resolveIdempotencyStore()?->release($idempotencyRecord);
                }

                throw new DatabaseOperationException(
                    failure: DatabaseOperationalFailure::Transient,
                    snapshot: $snapshot,
                    plan: $plan,
                    message: 'Database circuit breaker is open for this destination.',
                );
            }

            $attempt++;
            $events[] = new DatabaseDiagnosticEvent('started', $this->timestampNow(), [
                'attempt' => $attempt,
                'circuit_state' => $gateState,
            ]);

            try {
                $result = $this->runOperation($connection, $plan);
                [$materializedResult, $rowsRead] = $this->materializeResult($result);
                if ($rowsRead > $plan->maxRows) {
                    $events[] = new DatabaseDiagnosticEvent('failed', $this->timestampNow(), [
                        'attempt' => $attempt,
                        'reason' => 'max_rows_exceeded',
                        'rows' => $rowsRead,
                    ]);

                    $snapshot = $this->snapshot(
                        plan: $plan,
                        attempts: $attempt,
                        durationMs: $this->durationMs($startedAt),
                        rowsRead: $rowsRead,
                        affectedRows: $result->affectedRows,
                        outcome: 'failed',
                        failure: DatabaseOperationalFailure::ResourceExhausted,
                        retryable: false,
                        circuitState: $gateState,
                        events: $events,
                    );
                    $this->recordTelemetry($plan, $snapshot);
                    if ($idempotencyRecord instanceof DatabaseIdempotencyRecord) {
                        $this->resolveIdempotencyStore()?->release($idempotencyRecord);
                    }

                    throw new DatabaseOperationException(
                        failure: DatabaseOperationalFailure::ResourceExhausted,
                        snapshot: $snapshot,
                        plan: $plan,
                        message: sprintf('Operation returned [%d] rows and exceeded max_rows [%d].', $rowsRead, $plan->maxRows),
                    );
                }

                $this->circuitBreaker->recordSuccess($segment);
                $events[] = new DatabaseDiagnosticEvent('completed', $this->timestampNow(), [
                    'attempt' => $attempt,
                    'rows' => $rowsRead,
                    'affected_rows' => $result->affectedRows,
                ]);

                $snapshot = $this->snapshot(
                    plan: $plan,
                    attempts: $attempt,
                    durationMs: $this->durationMs($startedAt),
                    rowsRead: $rowsRead,
                    affectedRows: $result->affectedRows,
                    outcome: 'completed',
                    failure: null,
                    retryable: $plan->retryable,
                    circuitState: $this->circuitBreaker->currentState($segment),
                    events: $events,
                );
                $this->recordTelemetry($plan, $snapshot);
                if ($idempotencyRecord instanceof DatabaseIdempotencyRecord) {
                    $this->resolveIdempotencyStore()?->complete($idempotencyRecord);
                }

                return DatabaseOperationResult::success(
                    kind: $plan->operation->kind,
                    qr: $materializedResult->queryResult,
                    debug: [
                        'plan' => $plan,
                        'diagnostic' => $snapshot,
                    ],
                );
            } catch (DbalException $e) {
                $failure = $this->mapFailure($e);
                $retryable = $failure === DatabaseOperationalFailure::Transient && $plan->retryable;
                $circuitState = $failure === DatabaseOperationalFailure::Transient
                    ? $this->circuitBreaker->recordTransientFailure($segment, $plan->policy->circuitFailureThreshold)
                    : $this->circuitBreaker->currentState($segment);

                $events[] = new DatabaseDiagnosticEvent('failed', $this->timestampNow(), [
                    'attempt' => $attempt,
                    'failure' => $failure->value,
                    'stage' => $e->stage,
                ]);

                if ($retryable && $attempt <= $plan->retryLimit && !$plan->deadline->isExpired()) {
                    $events[] = new DatabaseDiagnosticEvent('progress', $this->timestampNow(), [
                        'attempt' => $attempt,
                        'action' => 'retry',
                    ]);

                    if ($plan->policy->retryBackoffMs > 0) {
                        usleep($plan->policy->retryBackoffMs * 1000);
                    }

                    continue;
                }

                $snapshot = $this->snapshot(
                    plan: $plan,
                    attempts: $attempt,
                    durationMs: $this->durationMs($startedAt),
                    rowsRead: 0,
                    affectedRows: 0,
                    outcome: 'failed',
                    failure: $failure,
                    retryable: $retryable,
                    circuitState: $circuitState,
                    events: $events,
                );
                $this->recordTelemetry($plan, $snapshot);
                if ($idempotencyRecord instanceof DatabaseIdempotencyRecord) {
                    if ($failure === DatabaseOperationalFailure::Transient) {
                        $this->resolveIdempotencyStore()?->release($idempotencyRecord);
                    } else {
                        $this->resolveIdempotencyStore()?->fail($idempotencyRecord);
                    }
                }

                throw new DatabaseOperationException(
                    failure: $failure,
                    snapshot: $snapshot,
                    plan: $plan,
                    message: $e->getMessage(),
                    previous: $e,
                );
            }
        }
    }

    private function runOperation(ConnectionInterface $connection, DatabaseOperationPlan $plan): DatabaseOperationResult
    {
        return match ($plan->operation->kind) {
            OperationKind::RawQuery => DatabaseOperationResult::success(
                kind: $plan->operation->kind,
                qr: $connection->executeQuery($plan->operation->sql, $plan->operation->params),
            ),
            default => DatabaseOperationResult::success(
                kind: $plan->operation->kind,
                qr: $connection->executeStatement($plan->operation->sql, $plan->operation->params),
            ),
        };
    }

    /**
     * @return array{DatabaseOperationResult,int}
     */
    private function materializeResult(DatabaseOperationResult $result): array
    {
        $queryResult = $result->queryResult;
        if (!$queryResult instanceof QueryResult || !$queryResult->isSelect()) {
            return [$result, 0];
        }

        $rows = $queryResult->fetchAllAssoc();
        $materialized = new QueryResult(
            isSelect: true,
            affectedRows: $queryResult->affectedRows(),
            columnMeta: $queryResult->columnMeta(),
            rowGenerator: static function () use ($rows): \Generator {
                foreach ($rows as $row) {
                    yield $row;
                }
            },
            cleanup: static function (): void {},
            columnCount: $queryResult->columnCount(),
        );

        return [
            DatabaseOperationResult::success(
                kind: $result->kind,
                qr: $materialized,
                debug: $result->debug,
            ),
            count($rows),
        ];
    }

    private function mapFailure(DbalException $e): DatabaseOperationalFailure
    {
        return match ($e->kind) {
            DatabaseFailureKind::Validation,
            DatabaseFailureKind::Capability,
            DatabaseFailureKind::Configuration => DatabaseOperationalFailure::InvalidPlan,
            DatabaseFailureKind::Authorization => DatabaseOperationalFailure::Unauthorized,
            DatabaseFailureKind::Timeout,
            DatabaseFailureKind::Concurrency,
            DatabaseFailureKind::Connectivity => DatabaseOperationalFailure::Transient,
            DatabaseFailureKind::Integrity,
            DatabaseFailureKind::Internal => DatabaseOperationalFailure::Permanent,
        };
    }

    private function isRetryableOperation(RawOperation $operation, DatabaseExecutionPolicy $policy): bool
    {
        if ($operation->kind === OperationKind::RawQuery) {
            return true;
        }

        if (
            $policy->retryMutationsWhenIdempotent
            && $policy->retryLimit > 0
            && $this->isMutatingOperation($operation->kind)
            && $this->hasIdempotencyKey($operation)
        ) {
            return true;
        }

        return false;
    }

    private function resolveConnectionName(RawOperation $operation, DatabaseContext $context): string
    {
        $comment = trim((string) $operation->comment);
        if ($comment !== '') {
            return $comment;
        }

        return $context->connection?->getDriverInfo()->databaseName !== ''
            ? (string) $context->connection?->getDriverInfo()->databaseName
            : 'default';
    }

    private function requireConnection(DatabaseContext $context): ConnectionInterface
    {
        if (!$context->connection instanceof ConnectionInterface) {
            throw new \RuntimeException('DatabaseContext requires a connected ConnectionInterface.');
        }

        return $context->connection;
    }

    private function safeSqlPreview(string $sql): string
    {
        $safe = preg_replace("/'[^']*'/", "'?'", $sql) ?? $sql;
        $safe = preg_replace('/\b\d+\b/', '?', $safe) ?? $safe;
        $safe = preg_replace('/\s+/', ' ', trim($safe)) ?? trim($safe);

        if (strlen($safe) > 160) {
            return substr($safe, 0, 157) . '...';
        }

        return $safe;
    }

    private function detectDepth(string $sql): int
    {
        $maxDepth = 0;
        $current = 0;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            if ($sql[$index] === '(') {
                $current++;
                $maxDepth = max($maxDepth, $current);
                continue;
            }

            if ($sql[$index] === ')') {
                $current = max(0, $current - 1);
            }
        }

        return $maxDepth;
    }

    /**
     * @param list<DatabaseDiagnosticEvent> $events
     */
    private function snapshot(
        DatabaseOperationPlan $plan,
        int $attempts,
        int $durationMs,
        int $rowsRead,
        int $affectedRows,
        string $outcome,
        ?DatabaseOperationalFailure $failure,
        bool $retryable,
        string $circuitState,
        array $events,
    ): DatabaseDiagnosticSnapshot {
        return new DatabaseDiagnosticSnapshot(
            fingerprint: $plan->fingerprint,
            connectionName: $plan->connectionName,
            driver: $plan->driver,
            attempts: $attempts,
            durationMs: $durationMs,
            rowsRead: $rowsRead,
            affectedRows: $affectedRows,
            slowQuery: $durationMs >= $plan->policy->slowQueryThresholdMs,
            outcome: $outcome,
            failure: $failure,
            retryable: $retryable,
            circuitState: $circuitState,
            deadlineRemainingMs: $plan->deadline->remainingMs(),
            events: $events,
        );
    }

    private function segmentKey(string $connectionName, string $driver, string $operationKind, string $logicalTarget): string
    {
        return $connectionName . '|' . $driver . '|' . $operationKind . '|' . $logicalTarget;
    }

    private function extractLogicalTarget(string $sql): string
    {
        $patterns = [
            '/\bdelete\s+from\s+([`"\\[]?[a-zA-Z0-9_.-]+[`"\\]]?)/i',
            '/\binsert\s+into\s+([`"\\[]?[a-zA-Z0-9_.-]+[`"\\]]?)/i',
            '/\bupdate\s+([`"\\[]?[a-zA-Z0-9_.-]+[`"\\]]?)/i',
            '/\bfrom\s+([`"\\[]?[a-zA-Z0-9_.-]+[`"\\]]?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $matches) === 1) {
                $target = trim((string) ($matches[1] ?? ''));
                $target = trim($target, "`\"[]");
                if ($target !== '') {
                    return strtolower($target);
                }
            }
        }

        return 'unknown';
    }

    private function recordTelemetry(DatabaseOperationPlan $plan, DatabaseDiagnosticSnapshot $snapshot): void
    {
        $telemetry = $this->resolveTelemetryStore();

        if (!$telemetry instanceof DatabaseTelemetryStore) {
            return;
        }

        $telemetry->record(
            plan: $plan,
            snapshot: $snapshot,
            segmentState: new DatabaseCircuitStateSnapshot(
                segment: $plan->circuitSegment,
                connectionName: $plan->connectionName,
                driver: $plan->driver,
                operationKind: $plan->operation->kind->value,
                logicalTarget: $plan->logicalTarget,
                state: $this->circuitBreaker->currentState($plan->circuitSegment),
                failureCount: $this->circuitBreaker->failureCount($plan->circuitSegment),
                openedAt: $this->circuitBreaker->openedAt($plan->circuitSegment),
            ),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFallbackDecision(DatabaseOperationPlan $plan): ?array
    {
        if (!$plan->policy->fallbackEnabled || $plan->policy->fallbackMode === 'off') {
            return null;
        }

        if ($plan->policy->fallbackMode === 'read_only_when_unhealthy' && $this->isReadOnlyOperation($plan->operation->kind)) {
            return null;
        }

        $healthStore = $this->resolveHealthStore();
        if (!$healthStore instanceof DatabaseHealthStoreInterface) {
            return null;
        }

        $aggregate = $healthStore->aggregate($plan->policy->fallbackAggregateLimit);
        $health = is_array($aggregate['health'] ?? null) ? $aggregate['health'] : [];
        $openSegments = (int) ($health['open_segments'] ?? 0);
        $halfOpenSegments = (int) ($health['half_open_segments'] ?? 0);

        if (
            $openSegments < $plan->policy->fallbackOpenSegmentsThreshold
            && $halfOpenSegments < $plan->policy->fallbackHalfOpenSegmentsThreshold
        ) {
            return null;
        }

        return $aggregate;
    }

    private function resolveTelemetryStore(): ?DatabaseTelemetryStore
    {
        if ($this->telemetry instanceof DatabaseTelemetryStore) {
            return $this->telemetry;
        }

        if ($this->telemetry instanceof \Closure) {
            $resolved = ($this->telemetry)();

            return $resolved instanceof DatabaseTelemetryStore ? $resolved : null;
        }

        return null;
    }

    private function resolveIdempotencyStore(): ?DatabaseIdempotencyStoreInterface
    {
        if ($this->idempotencyStore instanceof DatabaseIdempotencyStoreInterface) {
            return $this->idempotencyStore;
        }

        if ($this->idempotencyStore instanceof \Closure) {
            $resolved = ($this->idempotencyStore)();

            return $resolved instanceof DatabaseIdempotencyStoreInterface ? $resolved : null;
        }

        return null;
    }

    private function resolveHealthStore(): ?DatabaseHealthStoreInterface
    {
        if ($this->healthStore instanceof DatabaseHealthStoreInterface) {
            return $this->healthStore;
        }

        if ($this->healthStore instanceof \Closure) {
            $resolved = ($this->healthStore)();

            return $resolved instanceof DatabaseHealthStoreInterface ? $resolved : null;
        }

        return null;
    }

    private function isReadOnlyOperation(OperationKind $kind): bool
    {
        return match ($kind) {
            OperationKind::RawQuery,
            OperationKind::SqgSelect,
            OperationKind::OrmHydrate => true,
            default => false,
        };
    }

    private function isMutatingOperation(OperationKind $kind): bool
    {
        return match ($kind) {
            OperationKind::RawExecute,
            OperationKind::SqgInsert,
            OperationKind::SqgUpdate,
            OperationKind::SqgDelete,
            OperationKind::OrmInsert,
            OperationKind::OrmUpdate,
            OperationKind::OrmDelete,
            OperationKind::OrmBulkInsert => true,
            default => false,
        };
    }

    private function hasIdempotencyKey(RawOperation $operation): bool
    {
        return $operation->idempotencyKey !== null && trim($operation->idempotencyKey) !== '';
    }

    private function resolveIdempotencyKeyHash(RawOperation $operation): ?string
    {
        if (!$this->hasIdempotencyKey($operation)) {
            return null;
        }

        return hash('sha256', trim((string) $operation->idempotencyKey));
    }

    private function buildIdempotencyRecord(DatabaseOperationPlan $plan, DatabaseContext $context): ?DatabaseIdempotencyRecord
    {
        if (!$this->isMutatingOperation($plan->operation->kind)) {
            return null;
        }

        $keyHash = $this->resolveIdempotencyKeyHash($plan->operation);
        if ($keyHash === null) {
            return null;
        }

        return new DatabaseIdempotencyRecord(
            keyHash: $keyHash,
            operationFingerprint: $plan->fingerprint,
            requestId: $context->requestId,
            connectionName: $plan->connectionName,
            logicalTarget: $plan->logicalTarget,
            createdAt: $this->timestampNow(),
            nodeId: $this->resolveIdempotencyNodeId(),
            status: 'pending',
            expiresAt: $this->timestampAfterSeconds($plan->policy->idempotencyPendingTtlSeconds),
        );
    }

    private function resolveIdempotencyNodeId(): ?string
    {
        if (is_string($this->idempotencyNodeId)) {
            $value = trim($this->idempotencyNodeId);

            return $value !== '' ? $value : null;
        }

        if ($this->idempotencyNodeId instanceof \Closure) {
            $resolved = ($this->idempotencyNodeId)();

            if (is_string($resolved)) {
                $value = trim($resolved);

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }


    private function durationMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function timestampNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }

    private function timestampAfterSeconds(int $seconds): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('+%d seconds', max(1, $seconds)))
            ->format(\DATE_ATOM);
    }
}
