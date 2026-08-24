<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Trace\DatabaseDeadline;

final class DatabaseOperationRuntime
{
    public function __construct(
        private readonly DatabaseCircuitBreaker $circuitBreaker,
    ) {}

    public function plan(RawOperation $operation, DatabaseContext $context, DatabaseExecutionPolicy $policy): DatabaseOperationPlan
    {
        $connection = $this->requireConnection($context);
        $connection->connect();

        $connectionName = $this->resolveConnectionName($operation, $context);
        $driver = $connection->getDriverInfo()->driverName;
        $safePreview = $this->safeSqlPreview($operation->sql);
        $sqlFingerprint = hash('sha256', $safePreview);
        $deadline = $context->deadline ?? DatabaseDeadline::fromMs($policy->timeoutMs);
        $depth = $this->detectDepth($operation->sql);
        $retryable = $this->isRetryableOperation($operation);
        $retryLimit = $retryable ? $policy->retryLimit : 0;
        $payload = [
            'kind' => $operation->kind->value,
            'connection' => $connectionName,
            'driver' => $driver,
            'sql_fingerprint' => $sqlFingerprint,
            'max_rows' => $policy->maxRows,
            'max_depth' => $policy->maxDepth,
            'retry_limit' => $retryLimit,
            'tenant' => $context->tenantId,
            'request_id' => $context->requestId,
        ];

        return new DatabaseOperationPlan(
            operation: $operation,
            connectionName: $connectionName,
            driver: $driver,
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
                circuitState: $this->circuitBreaker->currentState($this->segmentKey($plan)),
                events: array_merge($events, [
                    new DatabaseDiagnosticEvent('failed', $this->timestampNow(), [
                        'reason' => 'query_depth_exceeded',
                    ]),
                ]),
            );

            throw new DatabaseOperationException(
                failure: DatabaseOperationalFailure::InvalidPlan,
                snapshot: $snapshot,
                plan: $plan,
                message: sprintf('Operation depth [%d] exceeds configured max depth [%d].', $plan->detectedDepth, $plan->maxDepth),
            );
        }

        $segment = $this->segmentKey($plan);
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

    private function isRetryableOperation(RawOperation $operation): bool
    {
        return $operation->kind === OperationKind::RawQuery;
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

    private function segmentKey(DatabaseOperationPlan $plan): string
    {
        return $plan->connectionName . '|' . $plan->driver . '|' . $plan->operation->kind->value;
    }

    private function durationMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function timestampNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
