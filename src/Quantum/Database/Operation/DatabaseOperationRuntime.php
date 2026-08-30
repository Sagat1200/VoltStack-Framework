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
use Quantum\Database\Operation\Engine\DatabaseRemoteReplayChallengeSigner;
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
        private readonly DatabaseRemoteReplayChallengeSigner|\Closure|null $remoteReplayChallengeSigner = null,
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
                $reuseAttempt = $this->reuseRemoteReplayValidationReceipt(
                    $existing,
                    $plan,
                    $currentNodeId,
                    $replayOrigin,
                    $evidenceVerification,
                );
                $existing = $reuseAttempt['record'];
                $remoteReplayValidation = $reuseAttempt['validation'] ?? $this->withReceiptCleanupTombstone(
                    $this->validateRemoteReplay(
                        $existing,
                        $plan,
                        $currentNodeId,
                        $replayOrigin,
                        $evidenceVerification,
                    ),
                    $reuseAttempt['cleanup_tombstone'],
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
                            ]
                                + $this->extractRemoteReplayValidationTelemetryDetails($remoteReplayValidation)
                                + $this->buildRemoteReplayValidationReceiptAdvertisementTelemetry($existing)),
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

    private function resolveRemoteReplayChallengeSigner(): ?DatabaseRemoteReplayChallengeSigner
    {
        if ($this->remoteReplayChallengeSigner instanceof DatabaseRemoteReplayChallengeSigner) {
            return $this->remoteReplayChallengeSigner;
        }

        if ($this->remoteReplayChallengeSigner instanceof \Closure) {
            $resolved = ($this->remoteReplayChallengeSigner)();

            return $resolved instanceof DatabaseRemoteReplayChallengeSigner ? $resolved : null;
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
    /**
     * @param array<string, mixed> $confirmationEvidence
     * @return array{
     *   record: DatabaseIdempotencyRecord,
     *   validation:?DatabaseRemoteReplayValidationResult,
     *   cleanup_tombstone:?array<string, mixed>
     * }
     */
    private function reuseRemoteReplayValidationReceipt(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
        ?string $currentNodeId,
        string $replayOrigin,
        array $confirmationEvidence,
    ): array {
        if (
            $replayOrigin !== 'federated_remote_node'
            || ($confirmationEvidence['verification_status'] ?? null) !== 'verified_persisted_evidence'
            || $plan->policy->remoteReplayValidationReceiptMaxAgeSeconds <= 0
        ) {
            return [
                'record' => $record,
                'validation' => null,
                'cleanup_tombstone' => null,
            ];
        }

        $prepared = $this->prepareRemoteReplayValidationReceiptReuseCandidates(
            $record,
            $plan,
            $confirmationEvidence,
        );
        $record = $prepared['record'];
        $propagatedReceipt = $prepared['propagated_receipt'];
        $cleanupTombstone = $prepared['cleanup_tombstone'];
        $receipt = $record->confirmation['remote_validation_receipt'] ?? null;
        if (is_array($receipt) && $receipt !== []) {
            $reused = $this->buildReusedRemoteReplayValidationResult(
                record: $record,
                plan: $plan,
                currentNodeId: $currentNodeId,
                confirmationEvidence: $confirmationEvidence,
                receipt: $receipt,
                receiptSource: 'record_store',
                persistReusedReceipt: false,
            );
            if ($reused instanceof DatabaseRemoteReplayValidationResult) {
                return [
                    'record' => $record,
                    'validation' => $this->withReceiptCleanupTombstone($reused, $cleanupTombstone),
                    'cleanup_tombstone' => $cleanupTombstone,
                ];
            }
        }

        if ($propagatedReceipt === null) {
            return [
                'record' => $record,
                'validation' => null,
                'cleanup_tombstone' => $cleanupTombstone,
            ];
        }

        $reused = $this->buildReusedRemoteReplayValidationResult(
            record: $record,
            plan: $plan,
            currentNodeId: $currentNodeId,
            confirmationEvidence: $confirmationEvidence,
            receipt: $propagatedReceipt['receipt'],
            receiptSource: 'health_snapshot',
            persistReusedReceipt: true,
            propagatedFromNodeId: $propagatedReceipt['report_node_id'],
            propagatedAt: $propagatedReceipt['generated_at'],
        );

        return [
            'record' => $record,
            'validation' => $reused instanceof DatabaseRemoteReplayValidationResult
                ? $this->withReceiptCleanupTombstone($reused, $cleanupTombstone)
                : null,
            'cleanup_tombstone' => $cleanupTombstone,
        ];
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     * @return array{
     *   record: DatabaseIdempotencyRecord,
     *   propagated_receipt: array{receipt:array<string, mixed>,report_node_id:?string,generated_at:?string}|null,
     *   cleanup_tombstone:?array<string, mixed>
     * }
     */
    private function prepareRemoteReplayValidationReceiptReuseCandidates(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
        array $confirmationEvidence,
    ): array {
        $cleanupTombstone = null;
        $hadLocalReceipt = is_array($record->confirmation['remote_validation_receipt'] ?? null)
            && ($record->confirmation['remote_validation_receipt'] ?? []) !== [];
        $record = $this->pruneExpiredReplicatedRemoteReplayValidationReceipt($record, $plan);
        if ($hadLocalReceipt && !is_array($record->confirmation['remote_validation_receipt'] ?? null)) {
            $cleanupTombstone = $this->buildRemoteReplayValidationReceiptCleanupAdvertisement($record);
        }
        $propagatedReceipt = $this->findPropagatedRemoteReplayValidationReceipt(
            $plan,
            $record,
            $confirmationEvidence,
        );
        $propagatedCleanupTombstone = $this->findPropagatedRemoteReplayValidationReceiptCleanupAdvertisement(
            $plan,
            $record,
            $confirmationEvidence,
        );
        if (
            $propagatedReceipt !== null
            && $propagatedCleanupTombstone !== null
            && $this->isCleanupTombstoneNewerThanPropagatedReceipt($propagatedReceipt, $propagatedCleanupTombstone)
        ) {
            $propagatedReceipt = null;
        }

        $localReceipt = $record->confirmation['remote_validation_receipt'] ?? null;
        if (
            is_array($localReceipt)
            && $localReceipt !== []
            && $propagatedCleanupTombstone !== null
            && $this->isCleanupTombstoneNewerThanLocalReplica($localReceipt, $propagatedCleanupTombstone)
        ) {
            $record = $this->pruneReplicatedRemoteReplayValidationReceipt(
                $record,
                [
                    'version' => 1,
                    'reason' => 'peer_cleanup_tombstone',
                    'pruned_at' => $this->timestampNow(),
                    'receipt_reuse_source' => $localReceipt['details']['receipt_reuse_source'] ?? null,
                    'report_node_id' => $localReceipt['details']['receipt_propagation_report_node_id'] ?? null,
                    'report_generated_at' => $localReceipt['details']['receipt_propagation_generated_at'] ?? null,
                    'tombstone_report_node_id' => $propagatedCleanupTombstone['report_node_id'],
                    'tombstone_report_generated_at' => $propagatedCleanupTombstone['generated_at'],
                    'tombstone_reason' => $propagatedCleanupTombstone['cleanup']['reason'] ?? null,
                    'tombstone_pruned_at' => $propagatedCleanupTombstone['cleanup']['pruned_at'] ?? null,
                ],
            );
            $cleanupTombstone = $this->buildRemoteReplayValidationReceiptCleanupAdvertisement($record);
            $localReceipt = null;
        }

        if (!is_array($localReceipt) || $localReceipt === []) {
            return [
                'record' => $record,
                'propagated_receipt' => $propagatedReceipt,
                'cleanup_tombstone' => $cleanupTombstone,
            ];
        }

        if (
            $propagatedReceipt !== null
            && $this->isPropagatedReceiptNewerThanLocalReplica($localReceipt, $propagatedReceipt)
        ) {
            $record = $this->pruneReplicatedRemoteReplayValidationReceipt(
                $record,
                [
                    'version' => 1,
                    'reason' => 'displaced_by_peer_advertisement',
                    'pruned_at' => $this->timestampNow(),
                    'receipt_reuse_source' => $localReceipt['details']['receipt_reuse_source'] ?? null,
                    'report_node_id' => $localReceipt['details']['receipt_propagation_report_node_id'] ?? null,
                    'report_generated_at' => $localReceipt['details']['receipt_propagation_generated_at'] ?? null,
                    'replacement_report_node_id' => $propagatedReceipt['report_node_id'],
                    'replacement_report_generated_at' => $propagatedReceipt['generated_at'],
                    'replacement_validated_at' => $propagatedReceipt['receipt']['validated_at'] ?? null,
                    'replacement_validator' => $propagatedReceipt['receipt']['validator'] ?? null,
                ],
            );
            $cleanupTombstone = $this->buildRemoteReplayValidationReceiptCleanupAdvertisement($record);
        }

        return [
            'record' => $record,
            'propagated_receipt' => $propagatedReceipt,
            'cleanup_tombstone' => $cleanupTombstone,
        ];
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     * @param array<string, mixed> $receipt
     */
    private function buildReusedRemoteReplayValidationResult(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
        ?string $currentNodeId,
        array $confirmationEvidence,
        array $receipt,
        string $receiptSource,
        bool $persistReusedReceipt,
        ?string $propagatedFromNodeId = null,
        ?string $propagatedAt = null,
    ): ?DatabaseRemoteReplayValidationResult {
        $status = trim((string) ($receipt['status'] ?? ''));
        $validatedByNodeId = trim((string) ($receipt['validated_by_node_id'] ?? ''));
        $normalizedCurrentNodeId = trim((string) ($currentNodeId ?? ''));
        $validatedAt = trim((string) ($receipt['validated_at'] ?? ''));
        $sourceNodeId = trim((string) (($receipt['source_node_id'] ?? null) ?: ($record->nodeId ?? '')));
        $confirmationFingerprint = trim((string) ($confirmationEvidence['confirmation_fingerprint'] ?? ''));
        $receiptConfirmationFingerprint = trim((string) (($receipt['confirmation_fingerprint'] ?? null) ?: (($receipt['details']['confirmation_fingerprint'] ?? null) ?: '')));
        $receiptDetails = is_array($receipt['details'] ?? null) ? $receipt['details'] : [];

        if (
            $status !== 'verified_remote_validation'
            || $validatedByNodeId === ''
            || $sourceNodeId === ''
            || trim((string) ($record->nodeId ?? '')) === ''
            || $sourceNodeId !== trim((string) ($record->nodeId ?? ''))
            || $validatedAt === ''
        ) {
            return null;
        }

        if ($confirmationFingerprint !== '' && $receiptConfirmationFingerprint !== '' && $receiptConfirmationFingerprint !== $confirmationFingerprint) {
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

        $reuseTrust = $this->resolveRemoteReplayValidationReceiptReuseTrust(
            $plan,
            $normalizedCurrentNodeId,
            $validatedByNodeId,
        );
        if ($reuseTrust === null) {
            return null;
        }

        $reuseScope = $this->normalizeRemoteReplayValidationReceiptReuseScope(
            $plan->policy->remoteReplayValidationReceiptReuseScope,
        );
        $receiptAttestation = $this->verifyRemoteReplayValidationReceiptAttestation(
            $receipt,
            $validatedByNodeId,
            $reuseTrust,
        );
        if ($reuseTrust !== 'current_node' && $receiptAttestation['status'] !== 'verified_receipt_attestation') {
            return null;
        }

        $replicatedLifecycle = $this->resolveReplicatedReceiptLifecycle($receipt, $plan);

        return DatabaseRemoteReplayValidationResult::verified(
            validator: 'cached_remote_validation_receipt',
            message: $reuseTrust === 'current_node'
                ? 'Reused a fresh remote validation receipt previously issued by the current node.'
                : 'Reused a fresh remote validation receipt previously issued by a trusted cluster node.',
            details: array_merge(
                $receiptDetails,
                $replicatedLifecycle,
                [
                    'receipt_reuse' => 'reused_fresh_receipt',
                    'receipt_reuse_scope' => $reuseScope,
                    'receipt_reuse_trust' => $reuseTrust,
                    'receipt_age_seconds' => $ageSeconds,
                    'receipt_validated_at' => $validatedAt,
                    'receipt_validated_by_node_id' => $validatedByNodeId,
                    'receipt_attestation_verification' => $receiptAttestation['status'],
                    'receipt_attestation_mode' => $receiptAttestation['mode'],
                    'receipt_attestation_key_id' => $receiptAttestation['key_id'],
                    'receipt_attested_by_node_id' => $receiptAttestation['attested_by_node_id'],
                    'receipt_attested_at' => $receiptAttestation['attested_at'],
                    'receipt_source_node_id' => $sourceNodeId,
                    'receipt_confirmation_fingerprint' => $receiptConfirmationFingerprint !== '' ? $receiptConfirmationFingerprint : null,
                    'receipt_original_validator' => isset($receipt['validator']) ? (string) $receipt['validator'] : null,
                    'receipt_reuse_source' => $receiptSource,
                    'receipt_propagation_source' => $receiptSource === 'health_snapshot'
                        ? 'health_snapshot'
                        : ($receiptDetails['receipt_propagation_source'] ?? null),
                    'receipt_propagation_report_node_id' => $receiptSource === 'health_snapshot'
                        ? $propagatedFromNodeId
                        : ($receiptDetails['receipt_propagation_report_node_id'] ?? null),
                    'receipt_propagation_generated_at' => $receiptSource === 'health_snapshot'
                        ? $propagatedAt
                        : ($receiptDetails['receipt_propagation_generated_at'] ?? null),
                    'receipt_propagation_age_seconds' => $receiptSource === 'health_snapshot'
                        ? $this->resolvePropagationAgeSeconds($propagatedAt)
                        : $this->resolvePropagationAgeSeconds(
                            isset($receiptDetails['receipt_propagation_generated_at'])
                                ? (string) $receiptDetails['receipt_propagation_generated_at']
                                : null,
                        ),
                    'receipt_propagation_report_trust' => $receiptSource === 'health_snapshot'
                        ? $this->resolvePropagationReportTrust($plan, $propagatedFromNodeId)
                        : ($receiptDetails['receipt_propagation_report_trust'] ?? null),
                    'persist_reused_receipt' => $persistReusedReceipt,
                    'receipt_payload' => $persistReusedReceipt ? $receipt : null,
                    'remote_replay_validation_receipt_max_age_seconds' => $plan->policy->remoteReplayValidationReceiptMaxAgeSeconds,
                ],
            ),
        );
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     * @return array{receipt:array<string, mixed>,report_node_id:?string,generated_at:?string}|null
     */
    private function findPropagatedRemoteReplayValidationReceipt(
        DatabaseOperationPlan $plan,
        DatabaseIdempotencyRecord $record,
        array $confirmationEvidence,
    ): ?array {
        $healthStore = $this->resolveHealthStore();
        if (!$healthStore instanceof DatabaseHealthStoreInterface) {
            return null;
        }

        $sourceNodeId = trim((string) ($record->nodeId ?? ''));
        if ($sourceNodeId === '') {
            return null;
        }

        $confirmationFingerprint = trim((string) ($confirmationEvidence['confirmation_fingerprint'] ?? ''));
        $reports = array_reverse($healthStore->recent($plan->policy->remoteReplayValidationReceiptPropagationHealthLimit));

        foreach ($reports as $report) {
            if (!$report instanceof DatabaseTelemetryReport) {
                continue;
            }

            $reportNodeId = trim((string) ($report->nodeId ?? ''));
            if (!$this->isTrustedPropagationReportNode($plan, $reportNodeId)) {
                continue;
            }

            if (!$this->isFreshPropagationReport($plan, $report->generatedAt)) {
                continue;
            }

            $summary = is_array($report->summary ?? null) ? $report->summary : [];
            $latest = array_reverse(is_array($summary['latest'] ?? null) ? $summary['latest'] : []);
            foreach ($latest as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $advertisedReceipt = is_array($entry['challenge_receipt_advertisement'] ?? null)
                    ? $entry['challenge_receipt_advertisement']
                    : null;
                if (!is_array($advertisedReceipt) || $advertisedReceipt === []) {
                    continue;
                }

                $advertisedSourceNodeId = trim((string) (($advertisedReceipt['source_node_id'] ?? null) ?: ''));
                if ($advertisedSourceNodeId !== $sourceNodeId) {
                    continue;
                }

                $advertisedConfirmationFingerprint = trim((string) (
                    ($advertisedReceipt['confirmation_fingerprint'] ?? null)
                    ?: (($advertisedReceipt['details']['confirmation_fingerprint'] ?? null) ?: '')
                ));
                if (
                    $confirmationFingerprint !== ''
                    && $advertisedConfirmationFingerprint !== ''
                    && $advertisedConfirmationFingerprint !== $confirmationFingerprint
                ) {
                    continue;
                }

                return [
                    'receipt' => $advertisedReceipt,
                    'report_node_id' => $reportNodeId !== '' ? $reportNodeId : null,
                    'generated_at' => $report->generatedAt,
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     * @return array{cleanup:array<string, mixed>,report_node_id:?string,generated_at:?string}|null
     */
    private function findPropagatedRemoteReplayValidationReceiptCleanupAdvertisement(
        DatabaseOperationPlan $plan,
        DatabaseIdempotencyRecord $record,
        array $confirmationEvidence,
    ): ?array {
        $healthStore = $this->resolveHealthStore();
        if (!$healthStore instanceof DatabaseHealthStoreInterface) {
            return null;
        }

        $sourceNodeId = trim((string) ($record->nodeId ?? ''));
        if ($sourceNodeId === '') {
            return null;
        }

        $confirmationFingerprint = trim((string) ($confirmationEvidence['confirmation_fingerprint'] ?? ''));
        $reports = array_reverse($healthStore->recent($plan->policy->remoteReplayValidationReceiptPropagationHealthLimit));

        foreach ($reports as $report) {
            if (!$report instanceof DatabaseTelemetryReport) {
                continue;
            }

            $reportNodeId = trim((string) ($report->nodeId ?? ''));
            if (!$this->isTrustedPropagationReportNode($plan, $reportNodeId)) {
                continue;
            }

            if (!$this->isFreshPropagationReport($plan, $report->generatedAt)) {
                continue;
            }

            $summary = is_array($report->summary ?? null) ? $report->summary : [];
            $latest = array_reverse(is_array($summary['latest'] ?? null) ? $summary['latest'] : []);
            foreach ($latest as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $cleanup = is_array($entry['challenge_receipt_tombstone_advertisement'] ?? null)
                    ? $entry['challenge_receipt_tombstone_advertisement']
                    : null;
                if (!is_array($cleanup) || $cleanup === []) {
                    continue;
                }

                $cleanupSourceNodeId = trim((string) (($cleanup['source_node_id'] ?? null) ?: ''));
                if ($cleanupSourceNodeId !== $sourceNodeId) {
                    continue;
                }

                $cleanupConfirmationFingerprint = trim((string) (($cleanup['confirmation_fingerprint'] ?? null) ?: ''));
                if (
                    $confirmationFingerprint !== ''
                    && $cleanupConfirmationFingerprint !== ''
                    && $cleanupConfirmationFingerprint !== $confirmationFingerprint
                ) {
                    continue;
                }

                return [
                    'cleanup' => $cleanup,
                    'report_node_id' => $reportNodeId !== '' ? $reportNodeId : null,
                    'generated_at' => $report->generatedAt,
                ];
            }
        }

        return null;
    }

    private function pruneExpiredReplicatedRemoteReplayValidationReceipt(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
    ): DatabaseIdempotencyRecord {
        $receipt = $record->confirmation['remote_validation_receipt'] ?? null;
        if (!is_array($receipt) || $receipt === []) {
            return $record;
        }

        $replicatedLifecycle = $this->resolveReplicatedReceiptLifecycle($receipt, $plan);
        if (($replicatedLifecycle['receipt_replica_freshness_status'] ?? null) !== 'expired_local_replica') {
            return $record;
        }

        return $this->pruneReplicatedRemoteReplayValidationReceipt(
            $record,
            [
                'version' => 1,
                'reason' => 'expired_local_replica',
                'pruned_at' => $this->timestampNow(),
                'replicated_at' => $replicatedLifecycle['receipt_replicated_at'] ?? null,
                'replica_age_seconds' => $replicatedLifecycle['receipt_replica_age_seconds'] ?? null,
                'replica_max_age_seconds' => $replicatedLifecycle['receipt_replica_max_age_seconds'] ?? null,
                'replica_expires_at' => $replicatedLifecycle['receipt_replica_expires_at'] ?? null,
                'receipt_reuse_source' => $replicatedLifecycle['receipt_reuse_source'] ?? null,
                'report_node_id' => $replicatedLifecycle['receipt_propagation_report_node_id'] ?? null,
                'report_generated_at' => $replicatedLifecycle['receipt_propagation_generated_at'] ?? null,
            ],
        );
    }

    /**
     * @param array<string, scalar|null> $cleanup
     */
    private function pruneReplicatedRemoteReplayValidationReceipt(
        DatabaseIdempotencyRecord $record,
        array $cleanup,
    ): DatabaseIdempotencyRecord {
        $cleanup = $this->enrichRemoteReplayValidationReceiptCleanup($record, $cleanup);
        $confirmation = $record->confirmation;
        unset($confirmation['remote_validation_receipt']);
        $confirmation['remote_validation_receipt_cleanup'] = $cleanup;

        $updated = $record->withStatus('completed', $confirmation);
        $this->resolveIdempotencyStore()?->complete($updated, $confirmation);

        return $updated;
    }

    /**
     * @param array<string, scalar|null> $cleanup
     * @return array<string, scalar|null>
     */
    private function enrichRemoteReplayValidationReceiptCleanup(
        DatabaseIdempotencyRecord $record,
        array $cleanup,
    ): array {
        $receipt = is_array($record->confirmation['remote_validation_receipt'] ?? null)
            ? $record->confirmation['remote_validation_receipt']
            : [];
        $details = is_array($receipt['details'] ?? null) ? $receipt['details'] : [];

        return $cleanup + [
            'source_node_id' => isset($receipt['source_node_id']) ? (string) $receipt['source_node_id'] : (is_string($record->nodeId) ? $record->nodeId : null),
            'confirmation_fingerprint' => isset($receipt['confirmation_fingerprint'])
                ? (string) $receipt['confirmation_fingerprint']
                : (isset($details['confirmation_fingerprint']) ? (string) $details['confirmation_fingerprint'] : null),
            'validated_at' => isset($receipt['validated_at']) ? (string) $receipt['validated_at'] : null,
            'validated_by_node_id' => isset($receipt['validated_by_node_id']) ? (string) $receipt['validated_by_node_id'] : null,
            'validator' => isset($receipt['validator']) ? (string) $receipt['validator'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $localReceipt
     * @param array{cleanup:array<string, mixed>,report_node_id:?string,generated_at:?string} $cleanupTombstone
     */
    private function isCleanupTombstoneNewerThanLocalReplica(
        array $localReceipt,
        array $cleanupTombstone,
    ): bool {
        $localDetails = is_array($localReceipt['details'] ?? null) ? $localReceipt['details'] : [];
        $localMarker = trim((string) (
            ($localDetails['receipt_propagation_generated_at'] ?? null)
            ?: (($localDetails['receipt_replicated_at'] ?? null) ?: ($localReceipt['validated_at'] ?? null))
        ));
        $cleanupMarker = trim((string) (
            ($cleanupTombstone['cleanup']['pruned_at'] ?? null)
            ?: (($cleanupTombstone['generated_at'] ?? null) ?: '')
        ));

        return $this->isTimestampNewer($cleanupMarker, $localMarker);
    }

    /**
     * @param array{receipt:array<string, mixed>,report_node_id:?string,generated_at:?string} $propagatedReceipt
     * @param array{cleanup:array<string, mixed>,report_node_id:?string,generated_at:?string} $cleanupTombstone
     */
    private function isCleanupTombstoneNewerThanPropagatedReceipt(
        array $propagatedReceipt,
        array $cleanupTombstone,
    ): bool {
        $receiptMarker = trim((string) (
            ($propagatedReceipt['generated_at'] ?? null)
            ?: (($propagatedReceipt['receipt']['validated_at'] ?? null) ?: '')
        ));
        $cleanupMarker = trim((string) (
            ($cleanupTombstone['cleanup']['pruned_at'] ?? null)
            ?: (($cleanupTombstone['generated_at'] ?? null) ?: '')
        ));

        return $this->isTimestampNewer($cleanupMarker, $receiptMarker);
    }

    private function isTimestampNewer(?string $candidate, ?string $reference): bool
    {
        $normalizedCandidate = trim((string) ($candidate ?? ''));
        $normalizedReference = trim((string) ($reference ?? ''));
        if ($normalizedCandidate === '' || $normalizedReference === '') {
            return false;
        }

        try {
            return (new \DateTimeImmutable($normalizedCandidate)) > (new \DateTimeImmutable($normalizedReference));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $localReceipt
     * @param array{receipt:array<string, mixed>,report_node_id:?string,generated_at:?string} $propagatedReceipt
     */
    private function isPropagatedReceiptNewerThanLocalReplica(
        array $localReceipt,
        array $propagatedReceipt,
    ): bool {
        $localDetails = is_array($localReceipt['details'] ?? null) ? $localReceipt['details'] : [];
        $reuseSource = trim((string) ($localDetails['receipt_reuse_source'] ?? ''));
        $propagationSource = trim((string) ($localDetails['receipt_propagation_source'] ?? ''));
        if ($reuseSource !== 'health_snapshot' && $propagationSource !== 'health_snapshot') {
            return false;
        }

        $localPropagationGeneratedAt = trim((string) ($localDetails['receipt_propagation_generated_at'] ?? ''));
        $candidateGeneratedAt = trim((string) ($propagatedReceipt['generated_at'] ?? ''));
        if ($localPropagationGeneratedAt !== '' && $candidateGeneratedAt !== '') {
            try {
                return (new \DateTimeImmutable($candidateGeneratedAt)) > (new \DateTimeImmutable($localPropagationGeneratedAt));
            } catch (\Throwable) {
                // Fall through to validated_at comparison.
            }
        }

        $localValidatedAt = trim((string) ($localReceipt['validated_at'] ?? ''));
        $candidateValidatedAt = trim((string) (($propagatedReceipt['receipt']['validated_at'] ?? null) ?: ''));
        if ($localValidatedAt !== '' && $candidateValidatedAt !== '') {
            try {
                return (new \DateTimeImmutable($candidateValidatedAt)) > (new \DateTimeImmutable($localValidatedAt));
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array<string, scalar|null>
     */
    private function resolveReplicatedReceiptLifecycle(array $receipt, DatabaseOperationPlan $plan): array
    {
        $details = is_array($receipt['details'] ?? null) ? $receipt['details'] : [];
        $reuseSource = trim((string) ($details['receipt_reuse_source'] ?? ''));
        $propagationSource = trim((string) ($details['receipt_propagation_source'] ?? ''));
        if ($reuseSource !== 'health_snapshot' && $propagationSource !== 'health_snapshot') {
            return [];
        }

        $replicatedAt = trim((string) ($details['receipt_replicated_at'] ?? ''));
        $replicatedByNodeId = trim((string) ($details['receipt_replicated_by_node_id'] ?? ''));
        $replicaAgeSeconds = $this->resolvePropagationAgeSeconds($replicatedAt !== '' ? $replicatedAt : null);
        $replicaMaxAgeSeconds = $plan->policy->remoteReplayValidationReceiptReplicatedMaxAgeSeconds;

        $expiresAt = null;
        if ($replicatedAt !== '' && $replicaMaxAgeSeconds > 0) {
            try {
                $expiresAt = (new \DateTimeImmutable($replicatedAt))
                    ->modify(sprintf('+%d seconds', $replicaMaxAgeSeconds))
                    ->format(DATE_ATOM);
            } catch (\Throwable) {
                $expiresAt = null;
            }
        }

        $freshnessStatus = 'replicated_local_receipt';
        if ($replicaMaxAgeSeconds > 0) {
            if ($replicaAgeSeconds === null) {
                $freshnessStatus = 'replicated_local_receipt_missing_timestamp';
            } elseif ($replicaAgeSeconds > $replicaMaxAgeSeconds) {
                $freshnessStatus = 'expired_local_replica';
            } else {
                $freshnessStatus = 'fresh_local_replica';
            }
        }

        return [
            'receipt_reuse_source' => $reuseSource !== '' ? $reuseSource : null,
            'receipt_propagation_source' => $propagationSource !== '' ? $propagationSource : null,
            'receipt_propagation_report_node_id' => isset($details['receipt_propagation_report_node_id']) ? (string) $details['receipt_propagation_report_node_id'] : null,
            'receipt_propagation_generated_at' => isset($details['receipt_propagation_generated_at']) ? (string) $details['receipt_propagation_generated_at'] : null,
            'receipt_replicated_at' => $replicatedAt !== '' ? $replicatedAt : null,
            'receipt_replicated_by_node_id' => $replicatedByNodeId !== '' ? $replicatedByNodeId : null,
            'receipt_replica_age_seconds' => $replicaAgeSeconds,
            'receipt_replica_max_age_seconds' => $replicaMaxAgeSeconds > 0 ? $replicaMaxAgeSeconds : null,
            'receipt_replica_expires_at' => $expiresAt,
            'receipt_replica_freshness_status' => $freshnessStatus,
        ];
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
        ) {
            return $record;
        }

        $details = is_array($validation->details) ? $validation->details : [];
        if (($details['receipt_reuse'] ?? null) === 'reused_fresh_receipt') {
            $propagatedReceipt = is_array($details['receipt_payload'] ?? null) ? $details['receipt_payload'] : null;
            if (!(bool) ($details['persist_reused_receipt'] ?? false) || $propagatedReceipt === null) {
                return $record;
            }

            $confirmation = $record->confirmation;
            unset($confirmation['remote_validation_receipt_cleanup']);
            $propagatedDetails = is_array($propagatedReceipt['details'] ?? null) ? $propagatedReceipt['details'] : [];
            $propagatedReceipt['details'] = $propagatedDetails + [
                'receipt_reuse' => $details['receipt_reuse'] ?? null,
                'receipt_reuse_scope' => $details['receipt_reuse_scope'] ?? null,
                'receipt_reuse_trust' => $details['receipt_reuse_trust'] ?? null,
                'receipt_reuse_source' => $details['receipt_reuse_source'] ?? null,
                'receipt_propagation_source' => $details['receipt_propagation_source'] ?? null,
                'receipt_propagation_report_node_id' => $details['receipt_propagation_report_node_id'] ?? null,
                'receipt_propagation_generated_at' => $details['receipt_propagation_generated_at'] ?? null,
                'receipt_propagation_age_seconds' => $details['receipt_propagation_age_seconds'] ?? null,
                'receipt_propagation_report_trust' => $details['receipt_propagation_report_trust'] ?? null,
                'receipt_replicated_at' => $this->timestampNow(),
                'receipt_replicated_by_node_id' => $currentNodeId,
                'receipt_replica_max_age_seconds' => $plan->policy->remoteReplayValidationReceiptReplicatedMaxAgeSeconds > 0
                    ? $plan->policy->remoteReplayValidationReceiptReplicatedMaxAgeSeconds
                    : null,
                'receipt_replica_expires_at' => $this->resolveReplicatedReceiptExpiresAt(
                    $plan->policy->remoteReplayValidationReceiptReplicatedMaxAgeSeconds,
                ),
            ];
            $confirmation['remote_validation_receipt'] = $propagatedReceipt;

            $updated = $record->withStatus('completed', $confirmation);
            $this->resolveIdempotencyStore()?->complete($updated, $confirmation);

            return $updated;
        }

        $confirmation = $record->confirmation;
        unset($confirmation['remote_validation_receipt_cleanup']);
        $confirmation['remote_validation_receipt'] = [
            'version' => 1,
            'status' => $validation->status,
            'validator' => $validation->validator,
            'message' => $validation->message,
            'validation_mode' => $plan->policy->remoteReplayValidationMode,
            'validated_at' => $this->timestampNow(),
            'validated_by_node_id' => $currentNodeId,
            'source_node_id' => $record->nodeId,
            'confirmation_fingerprint' => $confirmationEvidence['confirmation_fingerprint'] ?? null,
            'details' => $validation->details,
        ];
        $receiptAttestation = $this->createRemoteReplayValidationReceiptAttestation(
            $confirmation['remote_validation_receipt'],
            $currentNodeId,
        );
        if ($receiptAttestation !== null) {
            $confirmation['remote_validation_receipt']['receipt_attestation'] = $receiptAttestation;
        }

        $updated = $record->withStatus('completed', $confirmation);
        $this->resolveIdempotencyStore()?->complete($updated, $confirmation);

        return $updated;
    }

    private function resolveReplicatedReceiptExpiresAt(int $maxAgeSeconds): ?string
    {
        if ($maxAgeSeconds <= 0) {
            return null;
        }

        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify(sprintf('+%d seconds', $maxAgeSeconds))
                ->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeRemoteReplayValidationReceiptReuseScope(string $scope): string
    {
        $normalized = strtolower(trim($scope));

        return in_array($normalized, ['current_node', 'trusted_nodes', 'cluster'], true)
            ? $normalized
            : 'current_node';
    }

    private function resolveRemoteReplayValidationReceiptReuseTrust(
        DatabaseOperationPlan $plan,
        string $currentNodeId,
        string $validatedByNodeId,
    ): ?string {
        if ($validatedByNodeId === '') {
            return null;
        }

        $scope = $this->normalizeRemoteReplayValidationReceiptReuseScope(
            $plan->policy->remoteReplayValidationReceiptReuseScope,
        );

        if ($scope === 'cluster') {
            return $validatedByNodeId === $currentNodeId && $currentNodeId !== ''
                ? 'current_node'
                : 'cluster_node';
        }

        if ($currentNodeId === '') {
            return null;
        }

        if ($validatedByNodeId === $currentNodeId) {
            return 'current_node';
        }

        if ($scope !== 'trusted_nodes') {
            return null;
        }

        $trustedNodes = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => is_string($value) ? trim($value) : '',
            $plan->policy->remoteReplayValidationReceiptTrustedNodes,
        ))));

        return in_array($validatedByNodeId, $trustedNodes, true)
            ? 'trusted_node'
            : null;
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>|null
     */
    private function createRemoteReplayValidationReceiptAttestation(array $receipt, ?string $currentNodeId): ?array
    {
        $attestedByNodeId = trim((string) ($currentNodeId ?? ''));
        if ($attestedByNodeId === '') {
            return null;
        }

        $signer = $this->resolveRemoteReplayChallengeSigner();
        if ($signer === null) {
            return null;
        }

        $attestation = [
            'version' => 1,
            'mode' => 'validator_node_hmac_sha256',
            'attested_by_node_id' => $attestedByNodeId,
            'attested_at' => $this->timestampNow(),
            'key_id' => $signer->activeKeyId(),
            'protocol' => $signer->protocol(),
        ];
        $payload = $this->buildRemoteReplayValidationReceiptAttestationPayload($receipt, $attestation);
        $attestation['signature'] = $signer->signReceiptAttestation($payload, $attestation['key_id']);

        return $attestation;
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array{status:string,mode:?string,key_id:?string,attested_by_node_id:?string,attested_at:?string}
     */
    private function verifyRemoteReplayValidationReceiptAttestation(
        array $receipt,
        string $validatedByNodeId,
        string $reuseTrust,
    ): array {
        if ($reuseTrust === 'current_node') {
            return [
                'status' => 'not_required_current_node',
                'mode' => null,
                'key_id' => null,
                'attested_by_node_id' => $validatedByNodeId !== '' ? $validatedByNodeId : null,
                'attested_at' => null,
            ];
        }

        $attestation = is_array($receipt['receipt_attestation'] ?? null)
            ? $receipt['receipt_attestation']
            : [];
        $mode = trim((string) ($attestation['mode'] ?? ''));
        $keyId = trim((string) ($attestation['key_id'] ?? ''));
        $attestedByNodeId = trim((string) ($attestation['attested_by_node_id'] ?? ''));
        $attestedAt = trim((string) ($attestation['attested_at'] ?? ''));
        $signature = trim((string) ($attestation['signature'] ?? ''));

        if ($attestation === []) {
            return [
                'status' => 'missing_receipt_attestation',
                'mode' => null,
                'key_id' => null,
                'attested_by_node_id' => null,
                'attested_at' => null,
            ];
        }

        if ($mode !== 'validator_node_hmac_sha256') {
            return [
                'status' => 'unsupported_receipt_attestation',
                'mode' => $mode !== '' ? $mode : null,
                'key_id' => $keyId !== '' ? $keyId : null,
                'attested_by_node_id' => $attestedByNodeId !== '' ? $attestedByNodeId : null,
                'attested_at' => $attestedAt !== '' ? $attestedAt : null,
            ];
        }

        if ($validatedByNodeId === '' || $attestedByNodeId === '' || $attestedByNodeId !== $validatedByNodeId) {
            return [
                'status' => 'receipt_attestation_node_mismatch',
                'mode' => $mode,
                'key_id' => $keyId !== '' ? $keyId : null,
                'attested_by_node_id' => $attestedByNodeId !== '' ? $attestedByNodeId : null,
                'attested_at' => $attestedAt !== '' ? $attestedAt : null,
            ];
        }

        if ($attestedAt === '' || $signature === '') {
            return [
                'status' => 'incomplete_receipt_attestation',
                'mode' => $mode,
                'key_id' => $keyId !== '' ? $keyId : null,
                'attested_by_node_id' => $attestedByNodeId,
                'attested_at' => $attestedAt !== '' ? $attestedAt : null,
            ];
        }

        $signer = $this->resolveRemoteReplayChallengeSigner();
        if ($signer === null) {
            return [
                'status' => 'receipt_attestation_signer_unavailable',
                'mode' => $mode,
                'key_id' => $keyId !== '' ? $keyId : null,
                'attested_by_node_id' => $attestedByNodeId,
                'attested_at' => $attestedAt,
            ];
        }

        $payload = $this->buildRemoteReplayValidationReceiptAttestationPayload($receipt, [
            'mode' => $mode,
            'attested_by_node_id' => $attestedByNodeId,
            'attested_at' => $attestedAt,
            'key_id' => $keyId !== '' ? $keyId : null,
            'protocol' => $attestation['protocol'] ?? null,
        ]);
        $verified = $signer->verifyReceiptAttestation(
            $payload,
            $signature,
            $keyId !== '' ? $keyId : null,
        );

        return [
            'status' => $verified ? 'verified_receipt_attestation' : 'invalid_receipt_attestation',
            'mode' => $mode,
            'key_id' => $keyId !== '' ? $keyId : null,
            'attested_by_node_id' => $attestedByNodeId,
            'attested_at' => $attestedAt,
        ];
    }

    /**
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $attestation
     * @return array<string, mixed>
     */
    private function buildRemoteReplayValidationReceiptAttestationPayload(array $receipt, array $attestation): array
    {
        return [
            'version' => $receipt['version'] ?? 1,
            'status' => $receipt['status'] ?? null,
            'validator' => $receipt['validator'] ?? null,
            'message' => $receipt['message'] ?? null,
            'validation_mode' => $receipt['validation_mode'] ?? null,
            'validated_at' => $receipt['validated_at'] ?? null,
            'validated_by_node_id' => $receipt['validated_by_node_id'] ?? null,
            'source_node_id' => $receipt['source_node_id'] ?? null,
            'confirmation_fingerprint' => $receipt['confirmation_fingerprint'] ?? null,
            'details' => is_array($receipt['details'] ?? null) ? $receipt['details'] : [],
            'receipt_attestation' => [
                'version' => $attestation['version'] ?? 1,
                'mode' => $attestation['mode'] ?? null,
                'attested_by_node_id' => $attestation['attested_by_node_id'] ?? null,
                'attested_at' => $attestation['attested_at'] ?? null,
                'key_id' => $attestation['key_id'] ?? null,
                'protocol' => $attestation['protocol'] ?? null,
            ],
        ];
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
     * @return array<string, array<string, mixed>>
     */
    private function buildRemoteReplayValidationReceiptAdvertisementTelemetry(
        DatabaseIdempotencyRecord $record,
    ): array {
        $receipt = $record->confirmation['remote_validation_receipt'] ?? null;
        $telemetry = [];
        if (is_array($receipt) && $receipt !== []) {
            $advertisement = $this->buildRemoteReplayValidationReceiptAdvertisement($receipt);
            if ($advertisement !== null) {
                $telemetry['receipt_advertisement'] = $advertisement;
            }
        }

        $cleanupAdvertisement = $this->buildRemoteReplayValidationReceiptCleanupAdvertisement($record);
        if ($cleanupAdvertisement !== null) {
            $telemetry['receipt_tombstone_advertisement'] = $cleanupAdvertisement;
        }

        return $telemetry;
    }

    private function isTrustedPropagationReportNode(DatabaseOperationPlan $plan, string $reportNodeId): bool
    {
        $trustedNodes = $plan->policy->remoteReplayValidationReceiptPropagationTrustedNodes;
        if ($trustedNodes === []) {
            return true;
        }

        return $reportNodeId !== '' && in_array($reportNodeId, $trustedNodes, true);
    }

    private function isFreshPropagationReport(DatabaseOperationPlan $plan, ?string $generatedAt): bool
    {
        $maxAgeSeconds = $plan->policy->remoteReplayValidationReceiptPropagationMaxAgeSeconds;
        if ($maxAgeSeconds <= 0) {
            return true;
        }

        $ageSeconds = $this->resolvePropagationAgeSeconds($generatedAt);

        return $ageSeconds !== null && $ageSeconds <= $maxAgeSeconds;
    }

    private function resolvePropagationAgeSeconds(?string $generatedAt): ?int
    {
        $normalized = trim((string) ($generatedAt ?? ''));
        if ($normalized === '') {
            return null;
        }

        try {
            $generatedAtDate = new \DateTimeImmutable($normalized);
            $reference = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return max(0, $reference->getTimestamp() - $generatedAtDate->getTimestamp());
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePropagationReportTrust(DatabaseOperationPlan $plan, ?string $reportNodeId): string
    {
        return $plan->policy->remoteReplayValidationReceiptPropagationTrustedNodes === []
            ? 'unrestricted_report_node'
            : (
                $reportNodeId !== null && in_array($reportNodeId, $plan->policy->remoteReplayValidationReceiptPropagationTrustedNodes, true)
                ? 'trusted_report_node'
                : 'untrusted_report_node'
            );
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>|null
     */
    private function buildRemoteReplayValidationReceiptAdvertisement(array $receipt): ?array
    {
        if (trim((string) ($receipt['status'] ?? '')) !== 'verified_remote_validation') {
            return null;
        }

        $advertisement = [
            'version' => $receipt['version'] ?? 1,
            'status' => $receipt['status'] ?? null,
            'validator' => $receipt['validator'] ?? null,
            'message' => $receipt['message'] ?? null,
            'validation_mode' => $receipt['validation_mode'] ?? null,
            'validated_at' => $receipt['validated_at'] ?? null,
            'validated_by_node_id' => $receipt['validated_by_node_id'] ?? null,
            'source_node_id' => $receipt['source_node_id'] ?? null,
            'confirmation_fingerprint' => $receipt['confirmation_fingerprint'] ?? null,
            'details' => is_array($receipt['details'] ?? null) ? $receipt['details'] : [],
        ];

        if (is_array($receipt['receipt_attestation'] ?? null)) {
            $advertisement['receipt_attestation'] = $receipt['receipt_attestation'];
        }

        return $advertisement;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRemoteReplayValidationReceiptCleanupAdvertisement(
        DatabaseIdempotencyRecord $record,
    ): ?array {
        $cleanup = is_array($record->confirmation['remote_validation_receipt_cleanup'] ?? null)
            ? $record->confirmation['remote_validation_receipt_cleanup']
            : [];
        if ($cleanup === []) {
            return null;
        }

        $reason = trim((string) ($cleanup['reason'] ?? ''));
        $sourceNodeId = trim((string) ($cleanup['source_node_id'] ?? ''));
        $confirmationFingerprint = trim((string) ($cleanup['confirmation_fingerprint'] ?? ''));
        if ($reason === '' || $sourceNodeId === '' || $confirmationFingerprint === '') {
            return null;
        }

        return [
            'version' => $cleanup['version'] ?? 1,
            'reason' => $reason,
            'pruned_at' => $cleanup['pruned_at'] ?? null,
            'source_node_id' => $sourceNodeId,
            'confirmation_fingerprint' => $confirmationFingerprint,
            'validated_at' => $cleanup['validated_at'] ?? null,
            'validated_by_node_id' => $cleanup['validated_by_node_id'] ?? null,
            'validator' => $cleanup['validator'] ?? null,
            'report_node_id' => $cleanup['report_node_id'] ?? null,
            'report_generated_at' => $cleanup['report_generated_at'] ?? null,
            'replacement_report_node_id' => $cleanup['replacement_report_node_id'] ?? null,
            'replacement_report_generated_at' => $cleanup['replacement_report_generated_at'] ?? null,
            'replacement_validated_at' => $cleanup['replacement_validated_at'] ?? null,
            'tombstone_report_node_id' => $cleanup['tombstone_report_node_id'] ?? null,
            'tombstone_report_generated_at' => $cleanup['tombstone_report_generated_at'] ?? null,
            'tombstone_reason' => $cleanup['tombstone_reason'] ?? null,
            'tombstone_pruned_at' => $cleanup['tombstone_pruned_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|null $cleanupTombstone
     */
    private function withReceiptCleanupTombstone(
        DatabaseRemoteReplayValidationResult $validation,
        ?array $cleanupTombstone,
    ): DatabaseRemoteReplayValidationResult {
        if ($cleanupTombstone === null) {
            return $validation;
        }

        $details = is_array($validation->details) ? $validation->details : [];
        $details['receipt_tombstone_advertisement'] = $cleanupTombstone;

        return new DatabaseRemoteReplayValidationResult(
            $validation->status,
            $validation->validator,
            $validation->message,
            $details,
        );
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

    /**
     * @return array<string, scalar|null>
     */
    private function extractRemoteReplayValidationTelemetryDetails(
        DatabaseRemoteReplayValidationResult $validation,
    ): array {
        $details = is_array($validation->details) ? $validation->details : [];

        $telemetry = [];
        foreach (
            [
                'challenge_protocol',
                'protocol',
                'request_protocol',
                'response_protocol',
                'protocol_negotiated',
                'protocol_compatibility',
                'request_key_id',
                'response_key_id',
                'key_id',
                'receipt_reuse',
                'receipt_reuse_scope',
                'receipt_validated_by_node_id',
                'receipt_attestation_verification',
                'receipt_attestation_mode',
                'receipt_attestation_key_id',
                'receipt_attested_by_node_id',
                'receipt_attested_at',
                'receipt_age_seconds',
                'receipt_reuse_source',
                'receipt_propagation_source',
                'receipt_propagation_report_node_id',
                'receipt_propagation_generated_at',
                'receipt_propagation_age_seconds',
                'receipt_propagation_report_trust',
                'receipt_replicated_at',
                'receipt_replicated_by_node_id',
                'receipt_replica_age_seconds',
                'receipt_replica_max_age_seconds',
                'receipt_replica_expires_at',
                'receipt_replica_freshness_status',
                'endpoint',
                'endpoint_strategy',
                'http_status',
                'response_signature_verification',
                'challenge_validation',
                'challenge_validation_failure',
            ] as $key
        ) {
            $value = $details[$key] ?? null;
            if (is_scalar($value) || $value === null) {
                $telemetry[$key] = $value;
            }
        }

        if (is_array($details['receipt_tombstone_advertisement'] ?? null)) {
            $telemetry['receipt_tombstone_advertisement'] = $details['receipt_tombstone_advertisement'];
        }

        return $telemetry;
    }
}