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
use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayValidatorInterface;
use Quantum\Database\Trace\DatabaseDeadline;

final class DatabaseOperationRuntime
{
    public function __construct(
        private readonly DatabaseCircuitBreaker $circuitBreaker,
        private readonly DatabaseTelemetryStore|\Closure|null $telemetry = null,
        private readonly DatabaseHealthStoreInterface|\Closure|null $healthStore = null,
        private readonly DatabaseIdempotencyStoreInterface|\Closure|null $idempotencyStore = null,
        private readonly DatabaseRemoteReplayValidatorInterface|\Closure|null $remoteReplayValidator = null,
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
            if ($acquire instanceof DatabaseIdempotencyAcquireResult && $acquire->reason === 'replay') {
                $existing = $acquire->record ?? $idempotencyRecord;
                $confirmedAffectedRows = max(0, (int) ($existing->confirmation['affected_rows'] ?? 0));
                $replayResultSummary = $this->normalizeIdempotencyResultSummary($existing->confirmation);
                $replayReproducibility = $this->resolveReplayReproducibility($existing->confirmation);
                $confirmationEvidence = $this->normalizeIdempotencyConfirmationEvidence($existing);
                $evidenceVerification = $this->verifyIdempotencyConfirmationEvidence($existing, $confirmationEvidence);
                $currentNodeId = $this->resolveIdempotencyNodeId();
                $replayOrigin = $this->resolveReplayOrigin($existing->nodeId, $currentNodeId);
                $attestationFreshness = $this->resolveRemoteReplayAttestationFreshness(
                    $evidenceVerification,
                    $plan->policy->remoteReplayAttestationMaxAgeSeconds,
                    $replayOrigin,
                );
                $evidenceVerification = array_merge($evidenceVerification, $attestationFreshness);
                $evidenceTrustLevel = $this->resolveEvidenceTrustLevel(
                    $replayOrigin,
                    (string) ($evidenceVerification['verification_status'] ?? 'unknown'),
                    (string) ($evidenceVerification['attestation_verification_status'] ?? 'unknown'),
                );
                $evidenceVerification['trust_level'] = $evidenceTrustLevel;
                $remoteReplayAttestationWarning = $this->buildRemoteReplayAttestationWarning(
                    $existing,
                    $replayOrigin,
                    $plan->policy->remoteReplayAttestationMode,
                    $plan->policy->remoteReplayAttestationMaxAgeSeconds,
                    $evidenceVerification,
                );
                $remoteReplayValidation = $this->reuseRemoteReplayValidationReceipt(
                    $existing,
                    $plan,
                    $currentNodeId,
                    $replayOrigin,
                    $evidenceVerification,
                ) ?? $this->validateRemoteReplay(
                    $existing,
                    $plan,
                    $currentNodeId,
                    $replayOrigin,
                    $evidenceVerification,
                );
                $legacyReplayWarning = $this->buildLegacyReplayWarning(
                    $existing,
                    $replayReproducibility,
                    $plan->policy->legacyReplayMode,
                    $currentNodeId,
                    $replayOrigin,
                    $evidenceVerification,
                );
                if (($evidenceVerification['verification_status'] ?? null) === 'mismatch_persisted_evidence') {
                    $snapshot = $this->snapshot(
                        plan: $plan,
                        attempts: 0,
                        durationMs: 0,
                        rowsRead: 0,
                        affectedRows: 0,
                        outcome: 'cancelled',
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        retryable: false,
                        circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                        events: array_merge($events, [
                            new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), [
                                'reason' => 'idempotency_guard_confirmation_evidence_mismatch',
                                'status' => $existing->status,
                                'node_id' => $existing->nodeId,
                                'current_node_id' => $currentNodeId,
                                'replay_origin' => $replayOrigin,
                                'confirmation_fingerprint' => $evidenceVerification['confirmation_fingerprint'] ?? null,
                                'recomputed_confirmation_fingerprint' => $evidenceVerification['recomputed_confirmation_fingerprint'] ?? null,
                                'confirmation_evidence_mode' => $evidenceVerification['evidence_mode'] ?? null,
                                'verification_status' => $evidenceVerification['verification_status'] ?? null,
                                'attestation_verification_status' => $evidenceVerification['attestation_verification_status'] ?? null,
                                'evidence_trust_level' => $evidenceTrustLevel,
                            ]),
                        ]),
                    );
                    $this->recordTelemetry($plan, $snapshot);

                    throw new DatabaseOperationException(
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        snapshot: $snapshot,
                        plan: $plan,
                        message: sprintf(
                            'Database idempotency replay blocked for [%s] because persisted confirmation evidence fingerprint verification failed.',
                            $plan->operation->kind->value,
                        ),
                    );
                }
                if (($evidenceVerification['attestation_verification_status'] ?? null) === 'mismatch_source_node_attestation') {
                    $snapshot = $this->snapshot(
                        plan: $plan,
                        attempts: 0,
                        durationMs: 0,
                        rowsRead: 0,
                        affectedRows: 0,
                        outcome: 'cancelled',
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        retryable: false,
                        circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                        events: array_merge($events, [
                            new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), [
                                'reason' => 'idempotency_guard_source_node_attestation_mismatch',
                                'status' => $existing->status,
                                'node_id' => $existing->nodeId,
                                'current_node_id' => $currentNodeId,
                                'replay_origin' => $replayOrigin,
                                'confirmation_fingerprint' => $evidenceVerification['confirmation_fingerprint'] ?? null,
                                'attestation_fingerprint' => $evidenceVerification['attestation_fingerprint'] ?? null,
                                'recomputed_attestation_fingerprint' => $evidenceVerification['recomputed_attestation_fingerprint'] ?? null,
                                'attestation_mode' => $evidenceVerification['attestation_mode'] ?? null,
                                'attestation_verification_status' => $evidenceVerification['attestation_verification_status'] ?? null,
                                'attestation_freshness_status' => $evidenceVerification['attestation_freshness_status'] ?? null,
                                'attestation_age_seconds' => $evidenceVerification['attestation_age_seconds'] ?? null,
                                'evidence_trust_level' => $evidenceTrustLevel,
                            ]),
                        ]),
                    );
                    $this->recordTelemetry($plan, $snapshot);

                    throw new DatabaseOperationException(
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        snapshot: $snapshot,
                        plan: $plan,
                        message: sprintf(
                            'Database idempotency replay blocked for [%s] because source node attestation verification failed.',
                            $plan->operation->kind->value,
                        ),
                    );
                }
                if (
                    $replayOrigin === 'federated_remote_node'
                    && ($evidenceVerification['verification_status'] ?? null) === 'verified_persisted_evidence'
                    && ($evidenceVerification['attestation_verification_status'] ?? null) === 'verified_source_node_attestation'
                    && ($evidenceVerification['attestation_freshness_status'] ?? null) === 'stale_verified_attestation'
                    && $plan->policy->remoteReplayAttestationMode === 'require'
                ) {
                    $snapshot = $this->snapshot(
                        plan: $plan,
                        attempts: 0,
                        durationMs: 0,
                        rowsRead: 0,
                        affectedRows: 0,
                        outcome: 'cancelled',
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        retryable: false,
                        circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                        events: array_merge($events, [
                            new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), [
                                'reason' => 'idempotency_guard_remote_replay_attestation_stale_required',
                                'status' => $existing->status,
                                'node_id' => $existing->nodeId,
                                'current_node_id' => $currentNodeId,
                                'replay_origin' => $replayOrigin,
                                'remote_replay_attestation_mode' => $plan->policy->remoteReplayAttestationMode,
                                'remote_replay_attestation_max_age_seconds' => $plan->policy->remoteReplayAttestationMaxAgeSeconds,
                                'attestation_verification_status' => $evidenceVerification['attestation_verification_status'] ?? null,
                                'attestation_freshness_status' => $evidenceVerification['attestation_freshness_status'] ?? null,
                                'attestation_age_seconds' => $evidenceVerification['attestation_age_seconds'] ?? null,
                                'evidence_trust_level' => $evidenceTrustLevel,
                            ]),
                        ]),
                    );
                    $this->recordTelemetry($plan, $snapshot);

                    throw new DatabaseOperationException(
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        snapshot: $snapshot,
                        plan: $plan,
                        message: sprintf(
                            'Database idempotency replay blocked for [%s] because remote replay attestation age exceeded max age [%d] seconds.',
                            $plan->operation->kind->value,
                            $plan->policy->remoteReplayAttestationMaxAgeSeconds,
                        ),
                    );
                }
                if (
                    $replayOrigin === 'federated_remote_node'
                    && ($evidenceVerification['verification_status'] ?? null) === 'verified_persisted_evidence'
                    && ($evidenceVerification['attestation_verification_status'] ?? null) !== 'verified_source_node_attestation'
                    && $plan->policy->remoteReplayAttestationMode === 'require'
                ) {
                    $snapshot = $this->snapshot(
                        plan: $plan,
                        attempts: 0,
                        durationMs: 0,
                        rowsRead: 0,
                        affectedRows: 0,
                        outcome: 'cancelled',
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        retryable: false,
                        circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                        events: array_merge($events, [
                            new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), [
                                'reason' => 'idempotency_guard_remote_replay_attestation_required',
                                'status' => $existing->status,
                                'node_id' => $existing->nodeId,
                                'current_node_id' => $currentNodeId,
                                'replay_origin' => $replayOrigin,
                                'remote_replay_attestation_mode' => $plan->policy->remoteReplayAttestationMode,
                                'attestation_verification_status' => $evidenceVerification['attestation_verification_status'] ?? null,
                                'attestation_freshness_status' => $evidenceVerification['attestation_freshness_status'] ?? null,
                                'attestation_age_seconds' => $evidenceVerification['attestation_age_seconds'] ?? null,
                                'evidence_trust_level' => $evidenceTrustLevel,
                            ]),
                        ]),
                    );
                    $this->recordTelemetry($plan, $snapshot);

                    throw new DatabaseOperationException(
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        snapshot: $snapshot,
                        plan: $plan,
                        message: sprintf(
                            'Database idempotency replay blocked for [%s] because remote replay attestation mode [%s] requires verified source node attestation.',
                            $plan->operation->kind->value,
                            $plan->policy->remoteReplayAttestationMode,
                        ),
                    );
                }
                if (($remoteReplayValidation->status ?? null) === 'remote_validation_rejected') {
                    $snapshot = $this->snapshot(
                        plan: $plan,
                        attempts: 0,
                        durationMs: 0,
                        rowsRead: 0,
                        affectedRows: 0,
                        outcome: 'cancelled',
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        retryable: false,
                        circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                        events: array_merge($events, [
                            new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), array_merge([
                                'reason' => 'idempotency_guard_remote_replay_validation_rejected',
                                'status' => $existing->status,
                                'node_id' => $existing->nodeId,
                                'current_node_id' => $currentNodeId,
                                'replay_origin' => $replayOrigin,
                                'remote_replay_validation_mode' => $plan->policy->remoteReplayValidationMode,
                                'remote_validation_status' => $remoteReplayValidation->status,
                                'remote_validation_validator' => $remoteReplayValidation->validator,
                                'remote_validation_message' => $remoteReplayValidation->message,
                                'evidence_trust_level' => $evidenceTrustLevel,
                            ], $remoteReplayValidation->details)),
                        ]),
                    );
                    $this->recordTelemetry($plan, $snapshot);

                    throw new DatabaseOperationException(
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        snapshot: $snapshot,
                        plan: $plan,
                        message: sprintf(
                            'Database idempotency replay blocked for [%s] because active remote replay validation rejected the replay.',
                            $plan->operation->kind->value,
                        ),
                    );
                }
                if (
                    $replayOrigin === 'federated_remote_node'
                    && ($evidenceVerification['verification_status'] ?? null) === 'verified_persisted_evidence'
                    && $plan->policy->remoteReplayValidationMode === 'require'
                    && $remoteReplayValidation->status !== 'verified_remote_validation'
                ) {
                    $snapshot = $this->snapshot(
                        plan: $plan,
                        attempts: 0,
                        durationMs: 0,
                        rowsRead: 0,
                        affectedRows: 0,
                        outcome: 'cancelled',
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        retryable: false,
                        circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                        events: array_merge($events, [
                            new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), array_merge([
                                'reason' => 'idempotency_guard_remote_replay_validation_required',
                                'status' => $existing->status,
                                'node_id' => $existing->nodeId,
                                'current_node_id' => $currentNodeId,
                                'replay_origin' => $replayOrigin,
                                'remote_replay_validation_mode' => $plan->policy->remoteReplayValidationMode,
                                'remote_validation_status' => $remoteReplayValidation->status,
                                'remote_validation_validator' => $remoteReplayValidation->validator,
                                'remote_validation_message' => $remoteReplayValidation->message,
                                'evidence_trust_level' => $evidenceTrustLevel,
                            ], $remoteReplayValidation->details)),
                        ]),
                    );
                    $this->recordTelemetry($plan, $snapshot);

                    throw new DatabaseOperationException(
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        snapshot: $snapshot,
                        plan: $plan,
                        message: sprintf(
                            'Database idempotency replay blocked for [%s] because remote replay validation mode [%s] requires active validation success.',
                            $plan->operation->kind->value,
                            $plan->policy->remoteReplayValidationMode,
                        ),
                    );
                }
                if (
                    $replayReproducibility === 'legacy_reconstructed'
                    && $plan->policy->legacyReplayMode === 'block'
                ) {
                    $snapshot = $this->snapshot(
                        plan: $plan,
                        attempts: 0,
                        durationMs: 0,
                        rowsRead: 0,
                        affectedRows: 0,
                        outcome: 'cancelled',
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        retryable: false,
                        circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                        events: array_merge($events, [
                            new DatabaseDiagnosticEvent('cancelled', $this->timestampNow(), [
                                'reason' => 'idempotency_guard_legacy_replay_blocked',
                                'status' => $existing->status,
                                'node_id' => $existing->nodeId,
                                'current_node_id' => $currentNodeId,
                                'replay_origin' => $replayOrigin,
                                'legacy_replay_mode' => $plan->policy->legacyReplayMode,
                                'replay_reproducibility' => $replayReproducibility,
                                'confirmation_fingerprint' => $evidenceVerification['confirmation_fingerprint'] ?? null,
                                'confirmation_evidence_mode' => $evidenceVerification['evidence_mode'] ?? null,
                                'verification_status' => $evidenceVerification['verification_status'] ?? null,
                                'attestation_verification_status' => $evidenceVerification['attestation_verification_status'] ?? null,
                                'evidence_trust_level' => $evidenceTrustLevel,
                            ]),
                        ]),
                    );
                    $this->recordTelemetry($plan, $snapshot);

                    throw new DatabaseOperationException(
                        failure: DatabaseOperationalFailure::VerificationFailed,
                        snapshot: $snapshot,
                        plan: $plan,
                        message: sprintf(
                            'Database idempotency replay blocked for [%s] because confirmation reproducibility [%s] is incompatible with legacy replay mode [%s].',
                            $plan->operation->kind->value,
                            $replayReproducibility,
                            $plan->policy->legacyReplayMode,
                        ),
                    );
                }
                $existing = $this->persistRemoteReplayValidationReceipt(
                    $existing,
                    $plan,
                    $currentNodeId,
                    $replayOrigin,
                    $evidenceVerification,
                    $remoteReplayValidation,
                );
                $snapshot = $this->snapshot(
                    plan: $plan,
                    attempts: 0,
                    durationMs: 0,
                    rowsRead: 0,
                    affectedRows: $confirmedAffectedRows,
                    outcome: 'completed',
                    failure: null,
                    retryable: false,
                    circuitState: $this->circuitBreaker->currentState($plan->circuitSegment),
                    events: array_merge(
                        $events,
                        $this->buildRemoteReplayValidationWarningEvent(
                            $plan,
                            $remoteReplayValidation,
                            $existing,
                            $currentNodeId,
                            $replayOrigin,
                            $evidenceTrustLevel,
                        ),
                        $remoteReplayAttestationWarning !== null
                            ? [new DatabaseDiagnosticEvent('warning', $this->timestampNow(), $remoteReplayAttestationWarning)]
                            : [],
                        $legacyReplayWarning !== null
                            ? [new DatabaseDiagnosticEvent('warning', $this->timestampNow(), $legacyReplayWarning)]
                            : [],
                        [
                            new DatabaseDiagnosticEvent('completed', $this->timestampNow(), [
                                'reason' => 'idempotency_guard_replayed_confirmed',
                                'status' => $existing->status,
                                'node_id' => $existing->nodeId,
                                'current_node_id' => $currentNodeId,
                                'confirmed_at' => $existing->confirmation['confirmed_at'] ?? null,
                                'affected_rows' => $confirmedAffectedRows,
                                'replay_reproducibility' => $replayReproducibility,
                                'legacy_replay_mode' => $plan->policy->legacyReplayMode,
                                'remote_replay_attestation_mode' => $plan->policy->remoteReplayAttestationMode,
                                'remote_replay_attestation_max_age_seconds' => $plan->policy->remoteReplayAttestationMaxAgeSeconds,
                                'remote_replay_validation_mode' => $plan->policy->remoteReplayValidationMode,
                                'replay_origin' => $replayOrigin,
                                'confirmation_fingerprint' => $evidenceVerification['confirmation_fingerprint'] ?? null,
                                'confirmation_evidence_mode' => $evidenceVerification['evidence_mode'] ?? null,
                                'verification_status' => $evidenceVerification['verification_status'] ?? null,
                                'attestation_verification_status' => $evidenceVerification['attestation_verification_status'] ?? null,
                                'attestation_freshness_status' => $evidenceVerification['attestation_freshness_status'] ?? null,
                                'attestation_age_seconds' => $evidenceVerification['attestation_age_seconds'] ?? null,
                                'remote_validation_status' => $remoteReplayValidation->status,
                                'remote_validation_validator' => $remoteReplayValidation->validator,
                                'evidence_trust_level' => $evidenceTrustLevel,
                            ]),
                        ],
                    ),
                );
                $this->recordTelemetry($plan, $snapshot);

                return DatabaseOperationResult::successNoRows(
                    kind: $plan->operation->kind,
                    affectedRows: $confirmedAffectedRows,
                    debug: [
                        'plan' => $plan,
                        'diagnostic' => $snapshot,
                        'idempotency' => [
                            'status' => 'replayed_confirmed',
                            'source' => 'idempotency_confirmation',
                            'key_hash' => $idempotencyRecord->keyHash,
                            'record' => $existing->toArray(),
                            'confirmation' => $existing->confirmation,
                            'result_summary' => $replayResultSummary,
                            'replay_reproducibility' => $replayReproducibility,
                            'legacy_replay_mode' => $plan->policy->legacyReplayMode,
                            'remote_replay_attestation_mode' => $plan->policy->remoteReplayAttestationMode,
                            'remote_replay_attestation_max_age_seconds' => $plan->policy->remoteReplayAttestationMaxAgeSeconds,
                            'remote_replay_validation_mode' => $plan->policy->remoteReplayValidationMode,
                            'current_node_id' => $currentNodeId,
                            'source_node_id' => $existing->nodeId,
                            'replay_origin' => $replayOrigin,
                            'confirmation_evidence' => $evidenceVerification,
                            'remote_validation' => $remoteReplayValidation->toArray(),
                            'evidence_trust_level' => $evidenceTrustLevel,
                            'attestation_warning' => $remoteReplayAttestationWarning,
                            'warning' => $legacyReplayWarning,
                        ],
                    ],
                );
            }

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

                $confirmation = null;
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
                    $confirmation = [
                        'kind' => $plan->operation->kind->value,
                        'affected_rows' => $result->affectedRows,
                        'rows_read' => $rowsRead,
                        'outcome' => 'completed',
                        'confirmed_at' => $this->timestampNow(),
                        'summary_version' => 1,
                        'replay_reproducibility' => 'persisted_summary',
                        'result_summary' => $this->buildIdempotencyResultSummary($plan, $result, $rowsRead),
                    ];
                    $confirmation = array_merge(
                        $confirmation,
                        $this->buildIdempotencyConfirmationEvidence($idempotencyRecord, $confirmation),
                    );
                    $this->resolveIdempotencyStore()?->complete($idempotencyRecord, [
                        'kind' => $confirmation['kind'],
                        'affected_rows' => $confirmation['affected_rows'],
                        'rows_read' => $confirmation['rows_read'],
                        'outcome' => $confirmation['outcome'],
                        'confirmed_at' => $confirmation['confirmed_at'],
                        'summary_version' => $confirmation['summary_version'],
                        'replay_reproducibility' => $confirmation['replay_reproducibility'],
                        'result_summary' => $confirmation['result_summary'],
                        'source_node_id' => $confirmation['source_node_id'],
                        'evidence_version' => $confirmation['evidence_version'],
                        'evidence_mode' => $confirmation['evidence_mode'],
                        'confirmation_fingerprint' => $confirmation['confirmation_fingerprint'],
                        'attestation_version' => $confirmation['attestation_version'],
                        'attestation_mode' => $confirmation['attestation_mode'],
                        'attested_by_node_id' => $confirmation['attested_by_node_id'],
                        'attested_at' => $confirmation['attested_at'],
                        'attestation_fingerprint' => $confirmation['attestation_fingerprint'],
                    ]);
                }

                return DatabaseOperationResult::success(
                    kind: $plan->operation->kind,
                    qr: $materializedResult->queryResult,
                    debug: [
                        'plan' => $plan,
                        'diagnostic' => $snapshot,
                        'idempotency' => $idempotencyRecord instanceof DatabaseIdempotencyRecord ? [
                            'status' => 'completed',
                            'key_hash' => $idempotencyRecord->keyHash,
                            'confirmation' => $confirmation ?? [],
                            'result_summary' => is_array(($confirmation ?? [])['result_summary'] ?? null)
                                ? $confirmation['result_summary']
                                : [],
                        ] : null,
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

    private function resolveRemoteReplayValidator(): ?DatabaseRemoteReplayValidatorInterface
    {
        if ($this->remoteReplayValidator instanceof DatabaseRemoteReplayValidatorInterface) {
            return $this->remoteReplayValidator;
        }

        if ($this->remoteReplayValidator instanceof \Closure) {
            $resolved = ($this->remoteReplayValidator)();

            return $resolved instanceof DatabaseRemoteReplayValidatorInterface ? $resolved : null;
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

    /**
     * @return array<string, mixed>
     */
    private function buildIdempotencyResultSummary(
        DatabaseOperationPlan $plan,
        DatabaseOperationResult $result,
        int $rowsRead,
    ): array {
        return [
            'kind' => $plan->operation->kind->value,
            'is_select' => $result->queryResult?->isSelect() ?? false,
            'affected_rows' => $result->affectedRows,
            'rows_read' => $rowsRead,
            'column_count' => $result->queryResult?->columnCount() ?? 0,
            'result_type' => $result->queryResult?->isSelect() ? 'query_result' : 'success_no_rows',
        ];
    }

    /**
     * @param array<string, mixed> $confirmation
     * @return array<string, mixed>
     */
    private function normalizeIdempotencyResultSummary(array $confirmation): array
    {
        $existing = $confirmation['result_summary'] ?? null;
        if (is_array($existing) && $existing !== []) {
            return $existing;
        }

        return [
            'kind' => (string) ($confirmation['kind'] ?? 'unknown'),
            'is_select' => false,
            'affected_rows' => max(0, (int) ($confirmation['affected_rows'] ?? 0)),
            'rows_read' => max(0, (int) ($confirmation['rows_read'] ?? 0)),
            'column_count' => 0,
            'result_type' => 'success_no_rows',
        ];
    }

    /**
     * @param array<string, mixed> $confirmation
     */
    private function resolveReplayReproducibility(array $confirmation): string
    {
        $value = $confirmation['replay_reproducibility'] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return is_array($confirmation['result_summary'] ?? null)
            ? 'persisted_summary'
            : 'legacy_reconstructed';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildLegacyReplayWarning(
        DatabaseIdempotencyRecord $record,
        string $replayReproducibility,
        string $legacyReplayMode,
        ?string $currentNodeId,
        string $replayOrigin,
        array $confirmationEvidence,
    ): ?array {
        if ($replayReproducibility !== 'legacy_reconstructed' || $legacyReplayMode !== 'warn') {
            return null;
        }

        return [
            'reason' => 'idempotency_guard_legacy_replay_warning',
            'message' => 'Database idempotency replay used a legacy confirmation reconstructed without persisted result_summary.',
            'status' => $record->status,
            'node_id' => $record->nodeId,
            'current_node_id' => $currentNodeId,
            'confirmed_at' => $record->confirmation['confirmed_at'] ?? null,
            'replay_reproducibility' => $replayReproducibility,
            'legacy_replay_mode' => $legacyReplayMode,
            'replay_origin' => $replayOrigin,
            'confirmation_fingerprint' => $confirmationEvidence['confirmation_fingerprint'] ?? null,
            'confirmation_evidence_mode' => $confirmationEvidence['evidence_mode'] ?? null,
            'verification_status' => $confirmationEvidence['verification_status'] ?? null,
            'attestation_verification_status' => $confirmationEvidence['attestation_verification_status'] ?? null,
            'evidence_trust_level' => $confirmationEvidence['trust_level'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     */
    private function validateRemoteReplay(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
        ?string $currentNodeId,
        string $replayOrigin,
        array $confirmationEvidence,
    ): DatabaseRemoteReplayValidationResult {
        if (
            $replayOrigin !== 'federated_remote_node'
            || ($confirmationEvidence['verification_status'] ?? null) !== 'verified_persisted_evidence'
        ) {
            return DatabaseRemoteReplayValidationResult::verified(
                validator: 'not_applicable',
                message: 'Active remote replay validation is not required for this replay origin or evidence state.',
            );
        }

        $validator = $this->resolveRemoteReplayValidator();
        if (!$validator instanceof DatabaseRemoteReplayValidatorInterface) {
            return DatabaseRemoteReplayValidationResult::unavailable(
                validator: 'missing_remote_replay_validator',
                message: 'Active remote replay validation service is not available.',
            );
        }

        return $validator->validate($record, $plan, $currentNodeId, $confirmationEvidence);
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     */
    private function reuseRemoteReplayValidationReceipt(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
        ?string $currentNodeId,
        string $replayOrigin,
        array $confirmationEvidence,
    ): ?DatabaseRemoteReplayValidationResult {
        if (
            $replayOrigin !== 'federated_remote_node'
            || ($confirmationEvidence['verification_status'] ?? null) !== 'verified_persisted_evidence'
            || $plan->policy->remoteReplayValidationReceiptMaxAgeSeconds <= 0
        ) {
            return null;
        }

        $receipt = $record->confirmation['remote_validation_receipt'] ?? null;
        if (!is_array($receipt) || $receipt === []) {
            return null;
        }

        $status = trim((string) ($receipt['status'] ?? ''));
        $validatedByNodeId = trim((string) ($receipt['validated_by_node_id'] ?? ''));
        $normalizedCurrentNodeId = trim((string) ($currentNodeId ?? ''));
        $validatedAt = trim((string) ($receipt['validated_at'] ?? ''));

        if (
            $status !== 'verified_remote_validation'
            || $validatedByNodeId === ''
            || $normalizedCurrentNodeId === ''
            || $validatedByNodeId !== $normalizedCurrentNodeId
            || $validatedAt === ''
        ) {
            return null;
        }

        try {
            $validatedAtDate = new \DateTimeImmutable($validatedAt);
            $reference = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $ageSeconds = max(0, $reference->getTimestamp() - $validatedAtDate->getTimestamp());
        } catch (\Throwable) {
            return null;
        }

        if ($ageSeconds > $plan->policy->remoteReplayValidationReceiptMaxAgeSeconds) {
            return null;
        }

        return DatabaseRemoteReplayValidationResult::verified(
            validator: 'cached_remote_validation_receipt',
            message: 'Reused a fresh remote validation receipt previously issued by the current node.',
            details: [
                'receipt_reuse' => 'reused_fresh_receipt',
                'receipt_age_seconds' => $ageSeconds,
                'receipt_validated_at' => $validatedAt,
                'receipt_validated_by_node_id' => $validatedByNodeId,
                'receipt_original_validator' => isset($receipt['validator']) ? (string) $receipt['validator'] : null,
                'remote_replay_validation_receipt_max_age_seconds' => $plan->policy->remoteReplayValidationReceiptMaxAgeSeconds,
            ],
        );
    }

    /**
     * @return list<DatabaseDiagnosticEvent>
     */
    private function buildRemoteReplayValidationWarningEvent(
        DatabaseOperationPlan $plan,
        DatabaseRemoteReplayValidationResult $validation,
        DatabaseIdempotencyRecord $record,
        ?string $currentNodeId,
        string $replayOrigin,
        string $evidenceTrustLevel,
    ): array {
        if (
            $plan->policy->remoteReplayValidationMode !== 'warn'
            || $replayOrigin !== 'federated_remote_node'
            || $validation->status === 'verified_remote_validation'
        ) {
            return [];
        }

        return [
            new DatabaseDiagnosticEvent('warning', $this->timestampNow(), array_merge([
                'reason' => 'idempotency_guard_remote_replay_validation_warning',
                'status' => $record->status,
                'node_id' => $record->nodeId,
                'current_node_id' => $currentNodeId,
                'replay_origin' => $replayOrigin,
                'remote_replay_validation_mode' => $plan->policy->remoteReplayValidationMode,
                'remote_validation_status' => $validation->status,
                'remote_validation_validator' => $validation->validator,
                'remote_validation_message' => $validation->message,
                'evidence_trust_level' => $evidenceTrustLevel,
            ], $validation->details)),
        ];
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     */
    private function persistRemoteReplayValidationReceipt(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
        ?string $currentNodeId,
        string $replayOrigin,
        array $confirmationEvidence,
        DatabaseRemoteReplayValidationResult $validation,
    ): DatabaseIdempotencyRecord {
        if (
            $replayOrigin !== 'federated_remote_node'
            || ($confirmationEvidence['verification_status'] ?? null) !== 'verified_persisted_evidence'
            || (($validation->details['receipt_reuse'] ?? null) === 'reused_fresh_receipt')
        ) {
            return $record;
        }

        $confirmation = $record->confirmation;
        $confirmation['remote_validation_receipt'] = [
            'version' => 1,
            'status' => $validation->status,
            'validator' => $validation->validator,
            'message' => $validation->message,
            'validation_mode' => $plan->policy->remoteReplayValidationMode,
            'validated_at' => $this->timestampNow(),
            'validated_by_node_id' => $currentNodeId,
            'source_node_id' => $record->nodeId,
            'details' => $validation->details,
        ];

        $updated = $record->withStatus('completed', $confirmation);
        $this->resolveIdempotencyStore()?->complete($updated, $confirmation);

        return $updated;
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     * @return array<string, mixed>|null
     */
    private function buildRemoteReplayAttestationWarning(
        DatabaseIdempotencyRecord $record,
        string $replayOrigin,
        string $remoteReplayAttestationMode,
        int $remoteReplayAttestationMaxAgeSeconds,
        array $confirmationEvidence,
    ): ?array {
        if (
            $replayOrigin !== 'federated_remote_node'
            || $remoteReplayAttestationMode !== 'warn'
            || ($confirmationEvidence['verification_status'] ?? null) !== 'verified_persisted_evidence'
        ) {
            return null;
        }

        if (($confirmationEvidence['attestation_verification_status'] ?? null) !== 'verified_source_node_attestation') {
            return [
                'reason' => 'idempotency_guard_remote_replay_attestation_warning',
                'message' => 'Database idempotency replay used remote confirmation without verified source node attestation.',
                'status' => $record->status,
                'node_id' => $record->nodeId,
                'replay_origin' => $replayOrigin,
                'remote_replay_attestation_mode' => $remoteReplayAttestationMode,
                'remote_replay_attestation_max_age_seconds' => $remoteReplayAttestationMaxAgeSeconds,
                'attestation_verification_status' => $confirmationEvidence['attestation_verification_status'] ?? null,
                'attestation_freshness_status' => $confirmationEvidence['attestation_freshness_status'] ?? null,
                'attestation_age_seconds' => $confirmationEvidence['attestation_age_seconds'] ?? null,
                'confirmation_evidence_mode' => $confirmationEvidence['evidence_mode'] ?? null,
                'evidence_trust_level' => $confirmationEvidence['trust_level'] ?? null,
            ];
        }

        if (($confirmationEvidence['attestation_freshness_status'] ?? null) !== 'stale_verified_attestation') {
            return null;
        }

        return [
            'reason' => 'idempotency_guard_remote_replay_attestation_stale_warning',
            'message' => 'Database idempotency replay used remote confirmation with verified source node attestation older than the configured max age.',
            'status' => $record->status,
            'node_id' => $record->nodeId,
            'replay_origin' => $replayOrigin,
            'remote_replay_attestation_mode' => $remoteReplayAttestationMode,
            'remote_replay_attestation_max_age_seconds' => $remoteReplayAttestationMaxAgeSeconds,
            'attestation_verification_status' => $confirmationEvidence['attestation_verification_status'] ?? null,
            'attestation_freshness_status' => $confirmationEvidence['attestation_freshness_status'] ?? null,
            'attestation_age_seconds' => $confirmationEvidence['attestation_age_seconds'] ?? null,
            'confirmation_evidence_mode' => $confirmationEvidence['evidence_mode'] ?? null,
            'evidence_trust_level' => $confirmationEvidence['trust_level'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     * @return array<string, int|string|null>
     */
    private function resolveRemoteReplayAttestationFreshness(
        array $confirmationEvidence,
        int $remoteReplayAttestationMaxAgeSeconds,
        string $replayOrigin,
    ): array {
        if (
            $replayOrigin !== 'federated_remote_node'
            || ($confirmationEvidence['attestation_verification_status'] ?? null) !== 'verified_source_node_attestation'
        ) {
            return [
                'attestation_freshness_status' => 'not_applicable',
                'attestation_age_seconds' => null,
            ];
        }

        $attestedAt = isset($confirmationEvidence['attested_at']) ? trim((string) $confirmationEvidence['attested_at']) : '';
        if ($attestedAt === '') {
            return [
                'attestation_freshness_status' => 'unknown_attestation_age',
                'attestation_age_seconds' => null,
            ];
        }

        try {
            $attestedAtDate = new \DateTimeImmutable($attestedAt);
            $reference = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $ageSeconds = max(0, $reference->getTimestamp() - $attestedAtDate->getTimestamp());
        } catch (\Throwable) {
            return [
                'attestation_freshness_status' => 'unknown_attestation_age',
                'attestation_age_seconds' => null,
            ];
        }

        if ($remoteReplayAttestationMaxAgeSeconds <= 0) {
            return [
                'attestation_freshness_status' => 'fresh_verified_attestation',
                'attestation_age_seconds' => $ageSeconds,
            ];
        }

        return [
            'attestation_freshness_status' => $ageSeconds <= $remoteReplayAttestationMaxAgeSeconds
                ? 'fresh_verified_attestation'
                : 'stale_verified_attestation',
            'attestation_age_seconds' => $ageSeconds,
        ];
    }

    private function resolveReplayOrigin(?string $sourceNodeId, ?string $currentNodeId): string
    {
        $source = is_string($sourceNodeId) ? trim($sourceNodeId) : '';
        $current = is_string($currentNodeId) ? trim($currentNodeId) : '';

        if ($source === '' || $current === '') {
            return 'unknown_node';
        }

        return $source === $current
            ? 'local_node'
            : 'federated_remote_node';
    }

    /**
     * @param array<string, mixed> $confirmation
     * @return array<string, mixed>
     */
    private function buildIdempotencyConfirmationEvidence(
        DatabaseIdempotencyRecord $record,
        array $confirmation,
    ): array {
        $sourceNodeId = $record->nodeId;
        $payload = [
            'key_hash' => $record->keyHash,
            'operation_fingerprint' => $record->operationFingerprint,
            'request_id' => $record->requestId,
            'connection_name' => $record->connectionName,
            'logical_target' => $record->logicalTarget,
            'source_node_id' => $sourceNodeId,
            'confirmation' => [
                'kind' => $confirmation['kind'] ?? null,
                'affected_rows' => $confirmation['affected_rows'] ?? null,
                'rows_read' => $confirmation['rows_read'] ?? null,
                'outcome' => $confirmation['outcome'] ?? null,
                'confirmed_at' => $confirmation['confirmed_at'] ?? null,
                'replay_reproducibility' => $confirmation['replay_reproducibility'] ?? null,
                'result_summary' => $confirmation['result_summary'] ?? null,
            ],
        ];

        $confirmationFingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $evidence = [
            'source_node_id' => $sourceNodeId,
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => $sourceNodeId,
            'attested_at' => $confirmation['confirmed_at'] ?? null,
        ];

        $evidence['attestation_fingerprint'] = $this->computeIdempotencyConfirmationAttestationFingerprint($record, $evidence);

        return $evidence;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeIdempotencyConfirmationEvidence(DatabaseIdempotencyRecord $record): array
    {
        $confirmation = $record->confirmation;
        $sourceNodeId = isset($confirmation['source_node_id']) && is_string($confirmation['source_node_id']) && trim($confirmation['source_node_id']) !== ''
            ? trim($confirmation['source_node_id'])
            : $record->nodeId;
        $fingerprint = $confirmation['confirmation_fingerprint'] ?? null;

        if (is_string($fingerprint) && trim($fingerprint) !== '') {
            return [
                'source_node_id' => $sourceNodeId,
                'evidence_version' => isset($confirmation['evidence_version']) ? (int) $confirmation['evidence_version'] : null,
                'evidence_mode' => (string) ($confirmation['evidence_mode'] ?? 'persisted_evidence'),
                'confirmation_fingerprint' => trim($fingerprint),
                'attestation_version' => isset($confirmation['attestation_version']) ? (int) $confirmation['attestation_version'] : null,
                'attestation_mode' => isset($confirmation['attestation_mode']) ? (string) $confirmation['attestation_mode'] : null,
                'attested_by_node_id' => isset($confirmation['attested_by_node_id']) ? (string) $confirmation['attested_by_node_id'] : null,
                'attested_at' => isset($confirmation['attested_at']) ? (string) $confirmation['attested_at'] : null,
                'attestation_fingerprint' => isset($confirmation['attestation_fingerprint']) ? (string) $confirmation['attestation_fingerprint'] : null,
            ];
        }

        $payload = [
            'key_hash' => $record->keyHash,
            'operation_fingerprint' => $record->operationFingerprint,
            'request_id' => $record->requestId,
            'connection_name' => $record->connectionName,
            'logical_target' => $record->logicalTarget,
            'source_node_id' => $sourceNodeId,
            'confirmation' => [
                'kind' => $confirmation['kind'] ?? null,
                'affected_rows' => $confirmation['affected_rows'] ?? null,
                'rows_read' => $confirmation['rows_read'] ?? null,
                'outcome' => $confirmation['outcome'] ?? null,
                'confirmed_at' => $confirmation['confirmed_at'] ?? null,
                'replay_reproducibility' => $this->resolveReplayReproducibility($confirmation),
                'result_summary' => $this->normalizeIdempotencyResultSummary($confirmation),
            ],
        ];

        return [
            'source_node_id' => $sourceNodeId,
            'evidence_version' => null,
            'evidence_mode' => 'legacy_reconstructed_evidence',
            'confirmation_fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'attestation_version' => null,
            'attestation_mode' => null,
            'attested_by_node_id' => null,
            'attested_at' => null,
            'attestation_fingerprint' => null,
        ];
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     * @return array<string, mixed>
     */
    private function verifyIdempotencyConfirmationEvidence(
        DatabaseIdempotencyRecord $record,
        array $confirmationEvidence,
    ): array {
        $recomputedFingerprint = $this->computeIdempotencyConfirmationFingerprint(
            $record,
            $confirmationEvidence['source_node_id'] ?? $record->nodeId,
        );
        $storedFingerprint = $confirmationEvidence['confirmation_fingerprint'] ?? null;
        $evidenceMode = (string) ($confirmationEvidence['evidence_mode'] ?? 'unknown');

        if ($evidenceMode === 'persisted_evidence') {
            $verificationStatus = is_string($storedFingerprint) && trim($storedFingerprint) !== '' && trim($storedFingerprint) === $recomputedFingerprint
                ? 'verified_persisted_evidence'
                : 'mismatch_persisted_evidence';
            $attestationVerification = $this->verifyIdempotencyConfirmationAttestation($record, $confirmationEvidence);

            return array_merge($confirmationEvidence, [
                'verification_status' => $verificationStatus,
                'recomputed_confirmation_fingerprint' => $recomputedFingerprint,
                'attestation_verification_status' => $attestationVerification['attestation_verification_status'],
                'recomputed_attestation_fingerprint' => $attestationVerification['recomputed_attestation_fingerprint'],
            ]);
        }

        return array_merge($confirmationEvidence, [
            'verification_status' => 'reconstructed_legacy_evidence',
            'recomputed_confirmation_fingerprint' => $recomputedFingerprint,
            'attestation_verification_status' => 'not_attested_legacy',
            'recomputed_attestation_fingerprint' => null,
        ]);
    }

    private function computeIdempotencyConfirmationFingerprint(
        DatabaseIdempotencyRecord $record,
        mixed $sourceNodeId,
    ): string {
        $payload = [
            'key_hash' => $record->keyHash,
            'operation_fingerprint' => $record->operationFingerprint,
            'request_id' => $record->requestId,
            'connection_name' => $record->connectionName,
            'logical_target' => $record->logicalTarget,
            'source_node_id' => is_string($sourceNodeId) && trim($sourceNodeId) !== '' ? trim($sourceNodeId) : $record->nodeId,
            'confirmation' => [
                'kind' => $record->confirmation['kind'] ?? null,
                'affected_rows' => $record->confirmation['affected_rows'] ?? null,
                'rows_read' => $record->confirmation['rows_read'] ?? null,
                'outcome' => $record->confirmation['outcome'] ?? null,
                'confirmed_at' => $record->confirmation['confirmed_at'] ?? null,
                'replay_reproducibility' => $this->resolveReplayReproducibility($record->confirmation),
                'result_summary' => $this->normalizeIdempotencyResultSummary($record->confirmation),
            ],
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     * @return array{attestation_verification_status:string,recomputed_attestation_fingerprint:?string}
     */
    private function verifyIdempotencyConfirmationAttestation(
        DatabaseIdempotencyRecord $record,
        array $confirmationEvidence,
    ): array {
        $mode = isset($confirmationEvidence['attestation_mode']) ? trim((string) $confirmationEvidence['attestation_mode']) : '';
        if ($mode === '') {
            return [
                'attestation_verification_status' => 'no_attestation',
                'recomputed_attestation_fingerprint' => null,
            ];
        }

        $recomputedFingerprint = $this->computeIdempotencyConfirmationAttestationFingerprint($record, $confirmationEvidence);
        $storedFingerprint = $confirmationEvidence['attestation_fingerprint'] ?? null;
        $attestedBy = isset($confirmationEvidence['attested_by_node_id']) ? trim((string) $confirmationEvidence['attested_by_node_id']) : '';
        $sourceNodeId = isset($confirmationEvidence['source_node_id']) ? trim((string) $confirmationEvidence['source_node_id']) : '';
        $attestedAt = isset($confirmationEvidence['attested_at']) ? trim((string) $confirmationEvidence['attested_at']) : '';

        $verified = $mode === 'source_node_self_attested'
            && $attestedBy !== ''
            && $sourceNodeId !== ''
            && $attestedBy === $sourceNodeId
            && $attestedAt !== ''
            && is_string($storedFingerprint)
            && trim($storedFingerprint) !== ''
            && trim($storedFingerprint) === $recomputedFingerprint;

        return [
            'attestation_verification_status' => $verified
                ? 'verified_source_node_attestation'
                : 'mismatch_source_node_attestation',
            'recomputed_attestation_fingerprint' => $recomputedFingerprint,
        ];
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     */
    private function computeIdempotencyConfirmationAttestationFingerprint(
        DatabaseIdempotencyRecord $record,
        array $confirmationEvidence,
    ): string {
        $payload = [
            'key_hash' => $record->keyHash,
            'operation_fingerprint' => $record->operationFingerprint,
            'source_node_id' => $confirmationEvidence['source_node_id'] ?? $record->nodeId,
            'confirmation_fingerprint' => $confirmationEvidence['confirmation_fingerprint'] ?? null,
            'attestation_mode' => $confirmationEvidence['attestation_mode'] ?? null,
            'attested_by_node_id' => $confirmationEvidence['attested_by_node_id'] ?? null,
            'attested_at' => $confirmationEvidence['attested_at'] ?? null,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function resolveEvidenceTrustLevel(
        string $replayOrigin,
        string $verificationStatus,
        string $attestationVerificationStatus,
    ): string {
        if ($attestationVerificationStatus === 'mismatch_source_node_attestation') {
            return 'untrusted_attestation_mismatch';
        }

        return match ($verificationStatus) {
            'verified_persisted_evidence' => match ($replayOrigin) {
                'local_node' => 'local_verified_persisted',
                'federated_remote_node' => $attestationVerificationStatus === 'verified_source_node_attestation'
                    ? 'remote_attested_persisted'
                    : 'remote_verified_persisted',
                default => 'unknown_verified_persisted',
            },
            'reconstructed_legacy_evidence' => 'legacy_reconstructed',
            'mismatch_persisted_evidence' => 'untrusted_mismatch',
            default => 'unknown_trust',
        };
    }
}