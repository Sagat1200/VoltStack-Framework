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
        $remoteValidationReceipts = [
            'verified_remote_validation' => 0,
            'remote_validation_unavailable' => 0,
            'remote_validation_rejected' => 0,
            'not_applicable' => 0,
            'without_receipt' => 0,
            'unknown' => 0,
        ];
        $remoteChallengeTelemetry = [
            'with_details' => 0,
            'without_details' => 0,
            'protocols' => [],
            'compatibility' => [
                'compatible' => 0,
                'incompatible' => 0,
                'unknown' => 0,
                'not_applicable' => 0,
            ],
            'request_key_ids' => [],
            'response_key_ids' => [],
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
                    'remote_validation_receipts' => [
                        'verified_remote_validation' => 0,
                        'remote_validation_unavailable' => 0,
                        'remote_validation_rejected' => 0,
                        'not_applicable' => 0,
                        'without_receipt' => 0,
                        'unknown' => 0,
                    ],
                    'remote_challenge_telemetry' => [
                        'with_details' => 0,
                        'without_details' => 0,
                        'protocols' => [],
                        'compatibility' => [
                            'compatible' => 0,
                            'incompatible' => 0,
                            'unknown' => 0,
                            'not_applicable' => 0,
                        ],
                        'request_key_ids' => [],
                        'response_key_ids' => [],
                        'latest_protocol' => null,
                        'latest_request_key_id' => null,
                        'latest_response_key_id' => null,
                        'latest_compatibility' => null,
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

                $remoteValidationReceipt = self::resolveRemoteValidationReceiptStatus($record);
                if (!array_key_exists($remoteValidationReceipt, $remoteValidationReceipts)) {
                    $remoteValidationReceipts['unknown']++;
                    $nodeDetails[$nodeKey]['remote_validation_receipts']['unknown']++;
                } else {
                    $remoteValidationReceipts[$remoteValidationReceipt]++;
                    $nodeDetails[$nodeKey]['remote_validation_receipts'][$remoteValidationReceipt]++;
                }

                self::recordRemoteChallengeTelemetry(
                    $remoteChallengeTelemetry,
                    $nodeDetails[$nodeKey]['remote_challenge_telemetry'],
                    self::resolveRemoteChallengeTelemetry($record),
                );

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
            'remote_validation_receipts' => $remoteValidationReceipts,
            'remote_challenge_telemetry' => $remoteChallengeTelemetry,
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

    private static function resolveRemoteValidationReceiptStatus(DatabaseIdempotencyRecord $record): string
    {
        $receipt = $record->confirmation['remote_validation_receipt'] ?? null;
        if (!is_array($receipt) || $receipt === []) {
            return 'without_receipt';
        }

        $status = isset($receipt['status']) ? trim((string) $receipt['status']) : '';

        return $status !== '' ? $status : 'unknown';
    }

    /**
     * @return array{protocol:?string,compatibility:string,request_key_id:?string,response_key_id:?string}|null
     */
    private static function resolveRemoteChallengeTelemetry(DatabaseIdempotencyRecord $record): ?array
    {
        $receipt = $record->confirmation['remote_validation_receipt'] ?? null;
        if (!is_array($receipt) || $receipt === []) {
            return null;
        }

        $details = $receipt['details'] ?? null;
        if (!is_array($details) || $details === []) {
            return [
                'protocol' => null,
                'compatibility' => 'not_applicable',
                'request_key_id' => null,
                'response_key_id' => null,
            ];
        }

        $protocol = self::normalizeString(
            $details['protocol_negotiated']
                ?? $details['response_protocol']
                ?? $details['challenge_protocol']
                ?? $details['protocol']
                ?? null,
        );
        $compatibility = self::normalizeString($details['protocol_compatibility'] ?? null) ?? 'unknown';

        return [
            'protocol' => $protocol,
            'compatibility' => $compatibility,
            'request_key_id' => self::normalizeString($details['request_key_id'] ?? null),
            'response_key_id' => self::normalizeString($details['response_key_id'] ?? ($details['key_id'] ?? null)),
        ];
    }

    /**
     * @param array<string, mixed> $aggregateTelemetry
     * @param array<string, mixed> $nodeTelemetry
     * @param array{protocol:?string,compatibility:string,request_key_id:?string,response_key_id:?string}|null $telemetry
     */
    private static function recordRemoteChallengeTelemetry(
        array &$aggregateTelemetry,
        array &$nodeTelemetry,
        ?array $telemetry,
    ): void {
        if ($telemetry === null) {
            $aggregateTelemetry['without_details']++;
            $nodeTelemetry['without_details']++;
            $aggregateTelemetry['compatibility']['not_applicable']++;
            $nodeTelemetry['compatibility']['not_applicable']++;

            return;
        }

        $aggregateTelemetry['with_details']++;
        $nodeTelemetry['with_details']++;

        $compatibility = self::normalizeString($telemetry['compatibility'] ?? null) ?? 'unknown';
        if (!isset($aggregateTelemetry['compatibility'][$compatibility])) {
            $aggregateTelemetry['compatibility'][$compatibility] = 0;
        }
        if (!isset($nodeTelemetry['compatibility'][$compatibility])) {
            $nodeTelemetry['compatibility'][$compatibility] = 0;
        }
        $aggregateTelemetry['compatibility'][$compatibility]++;
        $nodeTelemetry['compatibility'][$compatibility]++;
        $nodeTelemetry['latest_compatibility'] = $compatibility;

        $protocol = self::normalizeString($telemetry['protocol'] ?? null);
        if ($protocol !== null) {
            $aggregateTelemetry['protocols'][$protocol] = (int) ($aggregateTelemetry['protocols'][$protocol] ?? 0) + 1;
            $nodeTelemetry['protocols'][$protocol] = (int) ($nodeTelemetry['protocols'][$protocol] ?? 0) + 1;
            $nodeTelemetry['latest_protocol'] = $protocol;
        }

        $requestKeyId = self::normalizeString($telemetry['request_key_id'] ?? null);
        if ($requestKeyId !== null) {
            $aggregateTelemetry['request_key_ids'][$requestKeyId] = (int) ($aggregateTelemetry['request_key_ids'][$requestKeyId] ?? 0) + 1;
            $nodeTelemetry['request_key_ids'][$requestKeyId] = (int) ($nodeTelemetry['request_key_ids'][$requestKeyId] ?? 0) + 1;
            $nodeTelemetry['latest_request_key_id'] = $requestKeyId;
        }

        $responseKeyId = self::normalizeString($telemetry['response_key_id'] ?? null);
        if ($responseKeyId !== null) {
            $aggregateTelemetry['response_key_ids'][$responseKeyId] = (int) ($aggregateTelemetry['response_key_ids'][$responseKeyId] ?? 0) + 1;
            $nodeTelemetry['response_key_ids'][$responseKeyId] = (int) ($nodeTelemetry['response_key_ids'][$responseKeyId] ?? 0) + 1;
            $nodeTelemetry['latest_response_key_id'] = $responseKeyId;
        }
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

    private static function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
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