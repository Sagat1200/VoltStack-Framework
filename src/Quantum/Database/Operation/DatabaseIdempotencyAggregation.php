<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final class DatabaseIdempotencyAggregation
{
    /**
     * @param list<DatabaseIdempotencyRecord> $records
     * @return array<string, mixed>
     */
    public static function aggregate(array $records): array
    {
        $requests = [];
        $connections = [];
        $targets = [];
        $nodes = [];
        $statuses = [
            'pending' => 0,
            'completed' => 0,
            'failed' => 0,
        ];
        $expiredPending = 0;
        $oldestAt = null;
        $latestAt = null;
        $replaySupport = [
            'persisted_summary' => 0,
            'legacy_reconstructed' => 0,
            'unknown' => 0,
        ];
        $evidenceVerification = [
            'verified_persisted_evidence' => 0,
            'reconstructed_legacy_evidence' => 0,
            'mismatch_persisted_evidence' => 0,
            'unknown' => 0,
        ];
        $attestationVerification = [
            'verified_source_node_attestation' => 0,
            'no_attestation' => 0,
            'not_attested_legacy' => 0,
            'mismatch_source_node_attestation' => 0,
            'unknown' => 0,
        ];
        $legacyReplayWarningCandidates = 0;
        $confirmations = [
            'with_confirmation' => 0,
            'without_confirmation' => 0,
            'summary_version_1' => 0,
            'legacy_without_summary' => 0,
        ];
        $nodeDetails = [];

        foreach ($records as $record) {
            $requests[$record->requestId] = true;
            $connections[$record->connectionName] = true;
            $targets[$record->logicalTarget] = true;
            if ($record->nodeId !== null && $record->nodeId !== '') {
                $nodes[$record->nodeId] = true;
            }
            $nodeKey = $record->nodeId !== null && trim($record->nodeId) !== ''
                ? $record->nodeId
                : 'unknown-node';

            if (!isset($nodeDetails[$nodeKey])) {
                $nodeDetails[$nodeKey] = [
                    'node_id' => $nodeKey,
                    'records' => 0,
                    'statuses' => [
                        'pending' => 0,
                        'completed' => 0,
                        'failed' => 0,
                    ],
                    'confirmations' => [
                        'with_confirmation' => 0,
                        'without_confirmation' => 0,
                        'legacy_without_summary' => 0,
                    ],
                    'replay_support' => [
                        'persisted_summary' => 0,
                        'legacy_reconstructed' => 0,
                        'unknown' => 0,
                    ],
                    'evidence_verification' => [
                        'verified_persisted_evidence' => 0,
                        'reconstructed_legacy_evidence' => 0,
                        'mismatch_persisted_evidence' => 0,
                        'unknown' => 0,
                    ],
                    'attestation_verification' => [
                        'verified_source_node_attestation' => 0,
                        'no_attestation' => 0,
                        'not_attested_legacy' => 0,
                        'mismatch_source_node_attestation' => 0,
                        'unknown' => 0,
                    ],
                    'legacy_replay_warning_candidates' => 0,
                    'latest_created_at' => null,
                ];
            }

            $nodeDetails[$nodeKey]['records']++;

            if (!array_key_exists($record->status, $statuses)) {
                $statuses[$record->status] = 0;
            }
            $statuses[$record->status]++;
            if (!array_key_exists($record->status, $nodeDetails[$nodeKey]['statuses'])) {
                $nodeDetails[$nodeKey]['statuses'][$record->status] = 0;
            }
            $nodeDetails[$nodeKey]['statuses'][$record->status]++;
            if ($record->isExpired()) {
                $expiredPending++;
            }
            if ($record->confirmation !== []) {
                $confirmations['with_confirmation']++;
                $nodeDetails[$nodeKey]['confirmations']['with_confirmation']++;

                $reproducibility = self::resolveReplayReproducibility($record->confirmation);
                if (!array_key_exists($reproducibility, $replaySupport)) {
                    $replaySupport['unknown']++;
                    $nodeDetails[$nodeKey]['replay_support']['unknown']++;
                } else {
                    $replaySupport[$reproducibility]++;
                    $nodeDetails[$nodeKey]['replay_support'][$reproducibility]++;
                }

                if (isset($record->confirmation['summary_version']) && (int) $record->confirmation['summary_version'] === 1) {
                    $confirmations['summary_version_1']++;
                }

                $verification = self::resolveEvidenceVerificationStatus($record);
                if (!array_key_exists($verification, $evidenceVerification)) {
                    $evidenceVerification['unknown']++;
                    $nodeDetails[$nodeKey]['evidence_verification']['unknown']++;
                } else {
                    $evidenceVerification[$verification]++;
                    $nodeDetails[$nodeKey]['evidence_verification'][$verification]++;
                }

                $attestation = self::resolveAttestationVerificationStatus($record);
                if (!array_key_exists($attestation, $attestationVerification)) {
                    $attestationVerification['unknown']++;
                    $nodeDetails[$nodeKey]['attestation_verification']['unknown']++;
                } else {
                    $attestationVerification[$attestation]++;
                    $nodeDetails[$nodeKey]['attestation_verification'][$attestation]++;
                }

                if ($reproducibility === 'legacy_reconstructed') {
                    $legacyReplayWarningCandidates++;
                    $confirmations['legacy_without_summary']++;
                    $nodeDetails[$nodeKey]['confirmations']['legacy_without_summary']++;
                    $nodeDetails[$nodeKey]['legacy_replay_warning_candidates']++;
                }
            } else {
                $confirmations['without_confirmation']++;
                $nodeDetails[$nodeKey]['confirmations']['without_confirmation']++;
            }

            if ($oldestAt === null || strcmp($record->createdAt, $oldestAt) < 0) {
                $oldestAt = $record->createdAt;
            }
            if ($latestAt === null || strcmp($record->createdAt, $latestAt) > 0) {
                $latestAt = $record->createdAt;
            }
            if (
                $nodeDetails[$nodeKey]['latest_created_at'] === null
                || strcmp($record->createdAt, (string) $nodeDetails[$nodeKey]['latest_created_at']) > 0
            ) {
                $nodeDetails[$nodeKey]['latest_created_at'] = $record->createdAt;
            }
        }

        ksort($nodeDetails);

        return [
            'records' => count($records),
            'requests' => count($requests),
            'connections' => count($connections),
            'logical_targets' => count($targets),
            'nodes' => count($nodes),
            'oldest_created_at' => $oldestAt,
            'latest_created_at' => $latestAt,
            'statuses' => $statuses,
            'expired_pending' => $expiredPending,
            'confirmations' => $confirmations,
            'replay_support' => $replaySupport,
            'evidence_verification' => $evidenceVerification,
            'attestation_verification' => $attestationVerification,
            'legacy_replay_warning_candidates' => $legacyReplayWarningCandidates,
            'nodes_detail' => array_values($nodeDetails),
        ];
    }

    /**
     * @param array<string, mixed> $confirmation
     */
    private static function resolveReplayReproducibility(array $confirmation): string
    {
        $value = $confirmation['replay_reproducibility'] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return is_array($confirmation['result_summary'] ?? null)
            ? 'persisted_summary'
            : 'legacy_reconstructed';
    }

    private static function resolveEvidenceVerificationStatus(DatabaseIdempotencyRecord $record): string
    {
        $confirmation = $record->confirmation;
        if ($confirmation === []) {
            return 'unknown';
        }

        $evidenceMode = (string) ($confirmation['evidence_mode'] ?? '');
        if ($evidenceMode === 'persisted_evidence') {
            $storedFingerprint = $confirmation['confirmation_fingerprint'] ?? null;

            return is_string($storedFingerprint)
                && trim($storedFingerprint) !== ''
                && trim($storedFingerprint) === self::computeConfirmationFingerprint($record)
                ? 'verified_persisted_evidence'
                : 'mismatch_persisted_evidence';
        }

        return 'reconstructed_legacy_evidence';
    }

    private static function computeConfirmationFingerprint(DatabaseIdempotencyRecord $record): string
    {
        $confirmation = $record->confirmation;
        $sourceNodeId = isset($confirmation['source_node_id']) && is_string($confirmation['source_node_id']) && trim($confirmation['source_node_id']) !== ''
            ? trim($confirmation['source_node_id'])
            : $record->nodeId;

        return hash('sha256', json_encode([
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
                'replay_reproducibility' => self::resolveReplayReproducibility($confirmation),
                'result_summary' => self::normalizeResultSummary($confirmation),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private static function resolveAttestationVerificationStatus(DatabaseIdempotencyRecord $record): string
    {
        $confirmation = $record->confirmation;
        if ($confirmation === []) {
            return 'unknown';
        }

        $mode = isset($confirmation['attestation_mode']) ? trim((string) $confirmation['attestation_mode']) : '';
        if ($mode === '') {
            return self::resolveEvidenceVerificationStatus($record) === 'reconstructed_legacy_evidence'
                ? 'not_attested_legacy'
                : 'no_attestation';
        }

        $recomputed = self::computeAttestationFingerprint($record);
        $stored = isset($confirmation['attestation_fingerprint']) ? trim((string) $confirmation['attestation_fingerprint']) : '';
        $attestedBy = isset($confirmation['attested_by_node_id']) ? trim((string) $confirmation['attested_by_node_id']) : '';
        $sourceNodeId = isset($confirmation['source_node_id']) ? trim((string) $confirmation['source_node_id']) : trim((string) ($record->nodeId ?? ''));
        $attestedAt = isset($confirmation['attested_at']) ? trim((string) $confirmation['attested_at']) : '';

        return $mode === 'source_node_self_attested'
            && $stored !== ''
            && $stored === $recomputed
            && $attestedBy !== ''
            && $sourceNodeId !== ''
            && $attestedBy === $sourceNodeId
            && $attestedAt !== ''
                ? 'verified_source_node_attestation'
                : 'mismatch_source_node_attestation';
    }

    private static function computeAttestationFingerprint(DatabaseIdempotencyRecord $record): string
    {
        $confirmation = $record->confirmation;
        $sourceNodeId = isset($confirmation['source_node_id']) && is_string($confirmation['source_node_id']) && trim($confirmation['source_node_id']) !== ''
            ? trim($confirmation['source_node_id'])
            : $record->nodeId;
        $confirmationFingerprint = isset($confirmation['confirmation_fingerprint']) && is_string($confirmation['confirmation_fingerprint']) && trim($confirmation['confirmation_fingerprint']) !== ''
            ? trim($confirmation['confirmation_fingerprint'])
            : self::computeConfirmationFingerprint($record);

        return hash('sha256', json_encode([
            'key_hash' => $record->keyHash,
            'operation_fingerprint' => $record->operationFingerprint,
            'source_node_id' => $sourceNodeId,
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_mode' => $confirmation['attestation_mode'] ?? null,
            'attested_by_node_id' => $confirmation['attested_by_node_id'] ?? null,
            'attested_at' => $confirmation['attested_at'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $confirmation
     * @return array<string, mixed>
     */
    private static function normalizeResultSummary(array $confirmation): array
    {
        $resultSummary = $confirmation['result_summary'] ?? null;
        if (is_array($resultSummary) && $resultSummary !== []) {
            return $resultSummary;
        }

        if ($confirmation === []) {
            return [];
        }

        return [
            'result_type' => 'success_no_rows',
            'is_select' => false,
            'affected_rows' => max(0, (int) ($confirmation['affected_rows'] ?? 0)),
            'rows_read' => max(0, (int) ($confirmation['rows_read'] ?? 0)),
            'column_count' => 0,
        ];
    }
}
