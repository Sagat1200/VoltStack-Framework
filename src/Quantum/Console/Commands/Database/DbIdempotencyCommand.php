<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;

final class DbIdempotencyCommand extends Command
{
    public function name(): string
    {
        return 'db:idempotency';
    }

    public function description(): string
    {
        return 'Inspecciona reservas persistidas de idempotencia Database.';
    }

    public function usage(): string
    {
        return 'db:idempotency [--key=raw-key] [--json] [--aggregate] [--limit=50]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--key=' => 'Busca una reserva concreta a partir de la idempotency key original.',
            '--json' => 'Imprime el resultado en JSON.',
            '--aggregate' => 'Muestra una vista agregada de reservas recientes.',
            '--limit=' => 'Cantidad maxima de reservas a considerar en la vista agregada/reciente.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();

        try {
            /** @var DatabaseIdempotencyStoreInterface $store */
            $store = $app->make(DatabaseIdempotencyStoreInterface::class);
            $currentNodeId = $this->resolveCurrentNodeId($app);
            $limit = $this->resolvePositiveIntOption($input, 'limit') ?? 50;
            $remoteReplayAttestationMode = (string) $app->config('database.idempotency.remote_replay_attestation_mode', 'allow');
            $remoteReplayAttestationMaxAgeSeconds = max(0, (int) $app->config('database.idempotency.remote_replay_attestation_max_age_seconds', 0));

            $lookupKey = $this->resolveLookupKeyHash($input);
            if ($lookupKey !== null) {
                $record = $store->find($lookupKey);

                if (!$record instanceof DatabaseIdempotencyRecord) {
                    $output->writeln('No hay reserva de idempotencia para esa key.');
                    return 0;
                }

                return $this->renderRecord(
                    $record,
                    $currentNodeId,
                    $remoteReplayAttestationMode,
                    $remoteReplayAttestationMaxAgeSeconds,
                    $input,
                    $output,
                );
            }

            if ($input->hasOption('aggregate')) {
                $aggregate = $store->aggregate($limit);
                $aggregate['current_node_id'] = $currentNodeId;
                $aggregate['node_perspective'] = $this->buildAggregateNodePerspective($aggregate, $currentNodeId);
                $aggregate['evidence_trust_summary'] = $this->buildAggregateEvidenceTrustSummary($aggregate, $currentNodeId);

                if ($input->hasOption('json')) {
                    $output->writeln((string) json_encode(
                        $aggregate,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ));
                    return 0;
                }

                $statuses = is_array($aggregate['statuses'] ?? null) ? $aggregate['statuses'] : [];
                $confirmations = is_array($aggregate['confirmations'] ?? null) ? $aggregate['confirmations'] : [];
                $replaySupport = is_array($aggregate['replay_support'] ?? null) ? $aggregate['replay_support'] : [];
                $evidenceVerification = is_array($aggregate['evidence_verification'] ?? null) ? $aggregate['evidence_verification'] : [];
                $attestationVerification = is_array($aggregate['attestation_verification'] ?? null) ? $aggregate['attestation_verification'] : [];
                $nodesDetail = is_array($aggregate['nodes_detail'] ?? null) ? $aggregate['nodes_detail'] : [];
                $nodePerspective = is_array($aggregate['node_perspective'] ?? null) ? $aggregate['node_perspective'] : [];
                $evidenceTrustSummary = is_array($aggregate['evidence_trust_summary'] ?? null) ? $aggregate['evidence_trust_summary'] : [];
                $output->writeln(sprintf(
                    'Database idempotency aggregate: records=%d requests=%d connections=%d targets=%d nodes=%d window=%s..%s',
                    (int) ($aggregate['records'] ?? 0),
                    (int) ($aggregate['requests'] ?? 0),
                    (int) ($aggregate['connections'] ?? 0),
                    (int) ($aggregate['logical_targets'] ?? 0),
                    (int) ($aggregate['nodes'] ?? 0),
                    (string) ($aggregate['oldest_created_at'] ?? 'n/a'),
                    (string) ($aggregate['latest_created_at'] ?? 'n/a'),
                ));
                $output->writeln(sprintf(
                    'Statuses: pending=%d completed=%d failed=%d expired_pending=%d',
                    (int) ($statuses['pending'] ?? 0),
                    (int) ($statuses['completed'] ?? 0),
                    (int) ($statuses['failed'] ?? 0),
                    (int) ($aggregate['expired_pending'] ?? 0),
                ));
                $output->writeln(sprintf(
                    'Confirmations: with_confirmation=%d without_confirmation=%d summary_version_1=%d legacy_without_summary=%d',
                    (int) ($confirmations['with_confirmation'] ?? 0),
                    (int) ($confirmations['without_confirmation'] ?? 0),
                    (int) ($confirmations['summary_version_1'] ?? 0),
                    (int) ($confirmations['legacy_without_summary'] ?? 0),
                ));
                $output->writeln(sprintf(
                    'Replay support: persisted_summary=%d legacy_reconstructed=%d warning_candidates=%d',
                    (int) ($replaySupport['persisted_summary'] ?? 0),
                    (int) ($replaySupport['legacy_reconstructed'] ?? 0),
                    (int) ($aggregate['legacy_replay_warning_candidates'] ?? 0),
                ));
                $output->writeln(sprintf(
                    'Verification: verified=%d reconstructed_legacy=%d mismatch=%d',
                    (int) ($evidenceVerification['verified_persisted_evidence'] ?? 0),
                    (int) ($evidenceVerification['reconstructed_legacy_evidence'] ?? 0),
                    (int) ($evidenceVerification['mismatch_persisted_evidence'] ?? 0),
                ));
                $output->writeln(sprintf(
                    'Attestation: verified=%d missing=%d legacy=%d mismatch=%d',
                    (int) ($attestationVerification['verified_source_node_attestation'] ?? 0),
                    (int) ($attestationVerification['no_attestation'] ?? 0),
                    (int) ($attestationVerification['not_attested_legacy'] ?? 0),
                    (int) ($attestationVerification['mismatch_source_node_attestation'] ?? 0),
                ));
                $output->writeln(sprintf(
                    'Perspective: current_node=%s local_records=%d remote_records=%d unknown_records=%d',
                    $currentNodeId ?? 'n/a',
                    (int) ($nodePerspective['local_records'] ?? 0),
                    (int) ($nodePerspective['remote_records'] ?? 0),
                    (int) ($nodePerspective['unknown_records'] ?? 0),
                ));
                $output->writeln(sprintf(
                    'Trust: local_verified=%d remote_attested=%d remote_verified=%d legacy_reconstructed=%d untrusted_mismatch=%d untrusted_attestation=%d unknown=%d',
                    (int) ($evidenceTrustSummary['local_verified_persisted'] ?? 0),
                    (int) ($evidenceTrustSummary['remote_attested_persisted'] ?? 0),
                    (int) ($evidenceTrustSummary['remote_verified_persisted'] ?? 0),
                    (int) ($evidenceTrustSummary['legacy_reconstructed'] ?? 0),
                    (int) ($evidenceTrustSummary['untrusted_mismatch'] ?? 0),
                    (int) ($evidenceTrustSummary['untrusted_attestation_mismatch'] ?? 0),
                    (int) ($evidenceTrustSummary['unknown_trust'] ?? 0),
                ));
                foreach ($nodesDetail as $node) {
                    if (!is_array($node)) {
                        continue;
                    }

                    $nodeStatuses = is_array($node['statuses'] ?? null) ? $node['statuses'] : [];
                    $nodeReplaySupport = is_array($node['replay_support'] ?? null) ? $node['replay_support'] : [];
                    $nodeEvidenceVerification = is_array($node['evidence_verification'] ?? null) ? $node['evidence_verification'] : [];
                    $nodeAttestationVerification = is_array($node['attestation_verification'] ?? null) ? $node['attestation_verification'] : [];
                    $nodePerspectiveKind = $this->resolveNodePerspective(
                        isset($node['node_id']) ? (string) $node['node_id'] : null,
                        $currentNodeId,
                    );
                    $nodeTrustSummary = $this->buildNodeEvidenceTrustSummary(
                        $nodeEvidenceVerification,
                        $nodeAttestationVerification,
                        $nodePerspectiveKind,
                    );
                    $output->writeln(sprintf(
                        'Node: %s perspective=%s records=%d completed=%d failed=%d pending=%d persisted_summary=%d legacy_reconstructed=%d verified=%d mismatch=%d attested=%d attestation_mismatch=%d trust_local=%d trust_remote_attested=%d trust_remote_verified=%d trust_legacy=%d warning_candidates=%d latest_created_at=%s',
                        (string) ($node['node_id'] ?? 'unknown-node'),
                        $nodePerspectiveKind,
                        (int) ($node['records'] ?? 0),
                        (int) ($nodeStatuses['completed'] ?? 0),
                        (int) ($nodeStatuses['failed'] ?? 0),
                        (int) ($nodeStatuses['pending'] ?? 0),
                        (int) ($nodeReplaySupport['persisted_summary'] ?? 0),
                        (int) ($nodeReplaySupport['legacy_reconstructed'] ?? 0),
                        (int) ($nodeEvidenceVerification['verified_persisted_evidence'] ?? 0),
                        (int) ($nodeEvidenceVerification['mismatch_persisted_evidence'] ?? 0),
                        (int) ($nodeAttestationVerification['verified_source_node_attestation'] ?? 0),
                        (int) ($nodeAttestationVerification['mismatch_source_node_attestation'] ?? 0),
                        (int) ($nodeTrustSummary['local_verified_persisted'] ?? 0),
                        (int) ($nodeTrustSummary['remote_attested_persisted'] ?? 0),
                        (int) ($nodeTrustSummary['remote_verified_persisted'] ?? 0),
                        (int) ($nodeTrustSummary['legacy_reconstructed'] ?? 0),
                        (int) ($node['legacy_replay_warning_candidates'] ?? 0),
                        (string) ($node['latest_created_at'] ?? 'n/a'),
                    ));
                }

                return 0;
            }

            $record = $store->latest();
            if (!$record instanceof DatabaseIdempotencyRecord) {
                $output->writeln('No hay reservas de idempotencia persistidas.');
                return 0;
            }

            return $this->renderRecord(
                $record,
                $currentNodeId,
                $remoteReplayAttestationMode,
                $remoteReplayAttestationMaxAgeSeconds,
                $input,
                $output,
            );
        } catch (\Throwable $e) {
            $output->error(sprintf('db:idempotency failed: %s', $e->getMessage()));
            return 1;
        }
    }

    private function renderRecord(
        DatabaseIdempotencyRecord $record,
        ?string $currentNodeId,
        string $remoteReplayAttestationMode,
        int $remoteReplayAttestationMaxAgeSeconds,
        Input $input,
        Output $output,
    ): int {
        $replayOrigin = $this->resolveNodePerspective($record->nodeId, $currentNodeId);
        if ($input->hasOption('json')) {
            $payload = $record->toArray();
            $payload['current_node_id'] = $currentNodeId;
            $payload['replay_origin'] = $replayOrigin;
            $payload['confirmation_evidence'] = $this->resolveConfirmationEvidence(
                $record,
                $replayOrigin,
                $remoteReplayAttestationMaxAgeSeconds,
            );
            $payload['evidence_trust_level'] = $payload['confirmation_evidence']['trust_level'] ?? null;

            $output->writeln((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
            return 0;
        }

        $output->writeln(sprintf(
            'Database idempotency: request=%s status=%s created_at=%s expires_at=%s expired=%s',
            $record->requestId,
            $record->status,
            $record->createdAt,
            $record->expiresAt ?? 'n/a',
            $record->isExpired() ? 'yes' : 'no',
        ));
        $output->writeln(sprintf(
            'Key hash: %s',
            $record->keyHash,
        ));
        $output->writeln(sprintf(
            'Operation: fingerprint=%s connection=%s target=%s node=%s',
            $record->operationFingerprint,
            $record->connectionName,
            $record->logicalTarget,
            $record->nodeId ?? 'n/a',
        ));
        $output->writeln(sprintf(
            'Replay origin: perspective=%s current_node=%s source_node=%s',
            $replayOrigin,
            $currentNodeId ?? 'n/a',
            $record->nodeId ?? 'n/a',
        ));
        if ($record->confirmation !== []) {
            $replayReproducibility = $this->resolveReplayReproducibility($record->confirmation);
            $confirmationEvidence = $this->resolveConfirmationEvidence(
                $record,
                $replayOrigin,
                $remoteReplayAttestationMaxAgeSeconds,
            );
            $output->writeln(sprintf(
                'Confirmation: kind=%s affected_rows=%d rows_read=%d outcome=%s confirmed_at=%s',
                (string) ($record->confirmation['kind'] ?? 'n/a'),
                (int) ($record->confirmation['affected_rows'] ?? 0),
                (int) ($record->confirmation['rows_read'] ?? 0),
                (string) ($record->confirmation['outcome'] ?? 'n/a'),
                (string) ($record->confirmation['confirmed_at'] ?? 'n/a'),
            ));
            $output->writeln(sprintf(
                'Replay support: reproducibility=%s summary_version=%s',
                $replayReproducibility,
                isset($record->confirmation['summary_version']) ? (string) $record->confirmation['summary_version'] : 'n/a',
            ));
            $output->writeln(sprintf(
                'Replay evidence: source_node=%s fingerprint=%s evidence_version=%s mode=%s',
                (string) ($confirmationEvidence['source_node_id'] ?? 'n/a'),
                (string) ($confirmationEvidence['confirmation_fingerprint'] ?? 'n/a'),
                isset($confirmationEvidence['evidence_version']) && $confirmationEvidence['evidence_version'] !== null
                    ? (string) $confirmationEvidence['evidence_version']
                    : 'n/a',
                (string) ($confirmationEvidence['evidence_mode'] ?? 'n/a'),
            ));
            $output->writeln(sprintf(
                'Replay verification: status=%s recomputed_fingerprint=%s',
                (string) ($confirmationEvidence['verification_status'] ?? 'n/a'),
                (string) ($confirmationEvidence['recomputed_confirmation_fingerprint'] ?? 'n/a'),
            ));
            $output->writeln(sprintf(
                'Replay attestation: status=%s freshness=%s age_seconds=%s mode=%s attested_by=%s attested_at=%s fingerprint=%s recomputed_fingerprint=%s',
                (string) ($confirmationEvidence['attestation_verification_status'] ?? 'n/a'),
                (string) ($confirmationEvidence['attestation_freshness_status'] ?? 'n/a'),
                isset($confirmationEvidence['attestation_age_seconds']) && $confirmationEvidence['attestation_age_seconds'] !== null
                    ? (string) $confirmationEvidence['attestation_age_seconds']
                    : 'n/a',
                (string) ($confirmationEvidence['attestation_mode'] ?? 'n/a'),
                (string) ($confirmationEvidence['attested_by_node_id'] ?? 'n/a'),
                (string) ($confirmationEvidence['attested_at'] ?? 'n/a'),
                (string) ($confirmationEvidence['attestation_fingerprint'] ?? 'n/a'),
                (string) ($confirmationEvidence['recomputed_attestation_fingerprint'] ?? 'n/a'),
            ));
            $output->writeln(sprintf(
                'Replay trust: level=%s',
                (string) ($confirmationEvidence['trust_level'] ?? 'n/a'),
            ));
            $replayWarning = $this->resolveReplaySupportWarning($record->confirmation);
            if ($replayWarning !== null) {
                $output->writeln(sprintf('Warning: %s', $replayWarning));
            }
            $verificationWarning = $this->resolveConfirmationVerificationWarning($confirmationEvidence);
            if ($verificationWarning !== null) {
                $output->writeln(sprintf('Evidence warning: %s', $verificationWarning));
            }
            $attestationWarning = $this->resolveRemoteReplayAttestationWarning(
                $confirmationEvidence,
                $replayOrigin,
                $remoteReplayAttestationMode,
                $remoteReplayAttestationMaxAgeSeconds,
            );
            if ($attestationWarning !== null) {
                $output->writeln(sprintf('Attestation warning: %s', $attestationWarning));
            }
            $resultSummary = $this->normalizeResultSummary($record->confirmation);
            if ($resultSummary !== []) {
                $output->writeln(sprintf(
                    'Result summary: type=%s is_select=%s affected_rows=%d rows_read=%d column_count=%d',
                    (string) ($resultSummary['result_type'] ?? 'n/a'),
                    ((bool) ($resultSummary['is_select'] ?? false)) ? 'yes' : 'no',
                    (int) ($resultSummary['affected_rows'] ?? 0),
                    (int) ($resultSummary['rows_read'] ?? 0),
                    (int) ($resultSummary['column_count'] ?? 0),
                ));
            }
        }

        return 0;
    }

    private function resolveLookupKeyHash(Input $input): ?string
    {
        $value = $input->option('key');
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return hash('sha256', trim($value));
    }

    private function resolvePositiveIntOption(Input $input, string $option): ?int
    {
        $value = $input->option($option);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    /**
     * @param array<string, mixed> $aggregate
     * @return array<string, int>
     */
    private function buildAggregateNodePerspective(array $aggregate, ?string $currentNodeId): array
    {
        $summary = [
            'local_records' => 0,
            'remote_records' => 0,
            'unknown_records' => 0,
        ];
        $nodesDetail = is_array($aggregate['nodes_detail'] ?? null) ? $aggregate['nodes_detail'] : [];

        foreach ($nodesDetail as $node) {
            if (!is_array($node)) {
                continue;
            }

            $records = max(0, (int) ($node['records'] ?? 0));
            $perspective = $this->resolveNodePerspective(
                isset($node['node_id']) ? (string) $node['node_id'] : null,
                $currentNodeId,
            );

            match ($perspective) {
                'local_node' => $summary['local_records'] += $records,
                'federated_remote_node' => $summary['remote_records'] += $records,
                default => $summary['unknown_records'] += $records,
            };
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConfirmationEvidence(
        DatabaseIdempotencyRecord $record,
        ?string $replayOrigin = null,
        int $remoteReplayAttestationMaxAgeSeconds = 0,
    ): array {
        $confirmation = $record->confirmation;
        $sourceNodeId = isset($confirmation['source_node_id']) && is_string($confirmation['source_node_id']) && trim($confirmation['source_node_id']) !== ''
            ? trim($confirmation['source_node_id'])
            : $record->nodeId;
        $fingerprint = $confirmation['confirmation_fingerprint'] ?? null;

        if (is_string($fingerprint) && trim($fingerprint) !== '') {
            $payload = [
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

            return $this->verifyConfirmationEvidence(
                $record,
                $payload,
                $replayOrigin,
                $remoteReplayAttestationMaxAgeSeconds,
            );
        }

        return $this->verifyConfirmationEvidence($record, [
            'source_node_id' => $sourceNodeId,
            'evidence_version' => null,
            'evidence_mode' => 'legacy_reconstructed_evidence',
            'confirmation_fingerprint' => hash('sha256', json_encode([
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
                    'result_summary' => $this->normalizeResultSummary($confirmation),
                ],
            ], JSON_THROW_ON_ERROR)),
            'attestation_version' => null,
            'attestation_mode' => null,
            'attested_by_node_id' => null,
            'attested_at' => null,
            'attestation_fingerprint' => null,
        ], $replayOrigin, $remoteReplayAttestationMaxAgeSeconds);
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function verifyConfirmationEvidence(
        DatabaseIdempotencyRecord $record,
        array $evidence,
        ?string $replayOrigin = null,
        int $remoteReplayAttestationMaxAgeSeconds = 0,
    ): array {
        $recomputedFingerprint = $this->computeConfirmationEvidenceFingerprint(
            $record,
            $evidence['source_node_id'] ?? $record->nodeId,
        );
        $storedFingerprint = $evidence['confirmation_fingerprint'] ?? null;
        $mode = (string) ($evidence['evidence_mode'] ?? 'unknown');

        if ($mode === 'persisted_evidence') {
            $verificationStatus = is_string($storedFingerprint) && trim($storedFingerprint) !== '' && trim($storedFingerprint) === $recomputedFingerprint
                ? 'verified_persisted_evidence'
                : 'mismatch_persisted_evidence';
            $attestation = $this->verifyConfirmationAttestation($record, $evidence);
            $freshness = $this->resolveAttestationFreshness(
                $evidence,
                $attestation['attestation_verification_status'],
                $replayOrigin ?? $this->resolveNodePerspective($record->nodeId, null),
                $remoteReplayAttestationMaxAgeSeconds,
            );

            return array_merge($evidence, [
                'verification_status' => $verificationStatus,
                'recomputed_confirmation_fingerprint' => $recomputedFingerprint,
                'trust_level' => $this->resolveEvidenceTrustLevel(
                    $replayOrigin ?? $this->resolveNodePerspective($record->nodeId, null),
                    $verificationStatus,
                    $attestation['attestation_verification_status'],
                ),
                'attestation_verification_status' => $attestation['attestation_verification_status'],
                'recomputed_attestation_fingerprint' => $attestation['recomputed_attestation_fingerprint'],
                'attestation_freshness_status' => $freshness['attestation_freshness_status'],
                'attestation_age_seconds' => $freshness['attestation_age_seconds'],
            ]);
        }

        return array_merge($evidence, [
            'verification_status' => 'reconstructed_legacy_evidence',
            'recomputed_confirmation_fingerprint' => $recomputedFingerprint,
            'trust_level' => $this->resolveEvidenceTrustLevel(
                $replayOrigin ?? $this->resolveNodePerspective($record->nodeId, null),
                'reconstructed_legacy_evidence',
                'not_attested_legacy',
            ),
            'attestation_verification_status' => 'not_attested_legacy',
            'recomputed_attestation_fingerprint' => null,
            'attestation_freshness_status' => 'not_applicable',
            'attestation_age_seconds' => null,
        ]);
    }

    private function computeConfirmationEvidenceFingerprint(DatabaseIdempotencyRecord $record, mixed $sourceNodeId): string
    {
        $confirmation = $record->confirmation;

        return hash('sha256', json_encode([
            'key_hash' => $record->keyHash,
            'operation_fingerprint' => $record->operationFingerprint,
            'request_id' => $record->requestId,
            'connection_name' => $record->connectionName,
            'logical_target' => $record->logicalTarget,
            'source_node_id' => is_string($sourceNodeId) && trim($sourceNodeId) !== '' ? trim($sourceNodeId) : $record->nodeId,
            'confirmation' => [
                'kind' => $confirmation['kind'] ?? null,
                'affected_rows' => $confirmation['affected_rows'] ?? null,
                'rows_read' => $confirmation['rows_read'] ?? null,
                'outcome' => $confirmation['outcome'] ?? null,
                'confirmed_at' => $confirmation['confirmed_at'] ?? null,
                'replay_reproducibility' => $this->resolveReplayReproducibility($confirmation),
                'result_summary' => $this->normalizeResultSummary($confirmation),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{attestation_verification_status:string,recomputed_attestation_fingerprint:?string}
     */
    private function verifyConfirmationAttestation(DatabaseIdempotencyRecord $record, array $evidence): array
    {
        $mode = isset($evidence['attestation_mode']) ? trim((string) $evidence['attestation_mode']) : '';
        if ($mode === '') {
            return [
                'attestation_verification_status' => 'no_attestation',
                'recomputed_attestation_fingerprint' => null,
            ];
        }

        $recomputed = $this->computeConfirmationAttestationFingerprint($record, $evidence);
        $stored = isset($evidence['attestation_fingerprint']) ? trim((string) $evidence['attestation_fingerprint']) : '';
        $attestedBy = isset($evidence['attested_by_node_id']) ? trim((string) $evidence['attested_by_node_id']) : '';
        $sourceNodeId = isset($evidence['source_node_id']) ? trim((string) $evidence['source_node_id']) : trim((string) ($record->nodeId ?? ''));
        $attestedAt = isset($evidence['attested_at']) ? trim((string) $evidence['attested_at']) : '';

        $verified = $mode === 'source_node_self_attested'
            && $stored !== ''
            && $stored === $recomputed
            && $attestedBy !== ''
            && $sourceNodeId !== ''
            && $attestedBy === $sourceNodeId
            && $attestedAt !== '';

        return [
            'attestation_verification_status' => $verified
                ? 'verified_source_node_attestation'
                : 'mismatch_source_node_attestation',
            'recomputed_attestation_fingerprint' => $recomputed,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function computeConfirmationAttestationFingerprint(DatabaseIdempotencyRecord $record, array $evidence): string
    {
        return hash('sha256', json_encode([
            'key_hash' => $record->keyHash,
            'operation_fingerprint' => $record->operationFingerprint,
            'source_node_id' => $evidence['source_node_id'] ?? $record->nodeId,
            'confirmation_fingerprint' => $evidence['confirmation_fingerprint'] ?? null,
            'attestation_mode' => $evidence['attestation_mode'] ?? null,
            'attested_by_node_id' => $evidence['attested_by_node_id'] ?? null,
            'attested_at' => $evidence['attested_at'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{attestation_freshness_status:string,attestation_age_seconds:?int}
     */
    private function resolveAttestationFreshness(
        array $evidence,
        string $attestationVerificationStatus,
        string $replayOrigin,
        int $remoteReplayAttestationMaxAgeSeconds,
    ): array {
        if (
            $replayOrigin !== 'federated_remote_node'
            || $attestationVerificationStatus !== 'verified_source_node_attestation'
        ) {
            return [
                'attestation_freshness_status' => 'not_applicable',
                'attestation_age_seconds' => null,
            ];
        }

        $attestedAt = isset($evidence['attested_at']) ? trim((string) $evidence['attested_at']) : '';
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

    /**
     * @param array<string, mixed> $confirmationEvidence
     */
    private function resolveConfirmationVerificationWarning(array $confirmationEvidence): ?string
    {
        return ($confirmationEvidence['verification_status'] ?? null) === 'mismatch_persisted_evidence'
            ? 'persisted confirmation fingerprint does not match the recomputed evidence payload; inspect for drift or tampering.'
            : null;
    }

    /**
     * @param array<string, mixed> $confirmationEvidence
     */
    private function resolveRemoteReplayAttestationWarning(
        array $confirmationEvidence,
        string $replayOrigin,
        string $remoteReplayAttestationMode,
        int $remoteReplayAttestationMaxAgeSeconds,
    ): ?string {
        if (
            $replayOrigin !== 'federated_remote_node'
            || $remoteReplayAttestationMode !== 'warn'
            || ($confirmationEvidence['verification_status'] ?? null) !== 'verified_persisted_evidence'
        ) {
            return null;
        }

        if (($confirmationEvidence['attestation_verification_status'] ?? null) !== 'verified_source_node_attestation') {
            return 'remote confirmation is persisted and consistent but lacks verified source node attestation; review before enforcing remote_replay_attestation_mode=require.';
        }

        if (($confirmationEvidence['attestation_freshness_status'] ?? null) !== 'stale_verified_attestation') {
            return null;
        }

        return sprintf(
            'remote confirmation uses verified source node attestation older than the configured max age (%d seconds).',
            $remoteReplayAttestationMaxAgeSeconds,
        );
    }

    /**
     * @param array<string, mixed> $confirmation
     * @return array<string, mixed>
     */
    private function normalizeResultSummary(array $confirmation): array
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
     * @param array<string, mixed> $confirmation
     */
    private function resolveReplaySupportWarning(array $confirmation): ?string
    {
        if ($this->resolveReplayReproducibility($confirmation) !== 'legacy_reconstructed') {
            return null;
        }

        return 'legacy confirmation reconstructed without persisted result_summary; review before enforcing legacy_replay_mode=block.';
    }

    private function resolveCurrentNodeId(object $app): ?string
    {
        if (!method_exists($app, 'config')) {
            return null;
        }

        $value = (string) $app->config(
            'database.idempotency.node_id',
            (string) $app->config('database.health.node_id', (string) $app->config('app.name', 'app')),
        );
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function resolveNodePerspective(?string $sourceNodeId, ?string $currentNodeId): string
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
     * @param array<string, mixed> $aggregate
     * @return array<string, int>
     */
    private function buildAggregateEvidenceTrustSummary(array $aggregate, ?string $currentNodeId): array
    {
        $summary = [
            'local_verified_persisted' => 0,
            'remote_attested_persisted' => 0,
            'remote_verified_persisted' => 0,
            'legacy_reconstructed' => 0,
            'untrusted_mismatch' => 0,
            'untrusted_attestation_mismatch' => 0,
            'unknown_trust' => 0,
        ];
        $nodesDetail = is_array($aggregate['nodes_detail'] ?? null) ? $aggregate['nodes_detail'] : [];

        foreach ($nodesDetail as $node) {
            if (!is_array($node)) {
                continue;
            }

            $perspective = $this->resolveNodePerspective(
                isset($node['node_id']) ? (string) $node['node_id'] : null,
                $currentNodeId,
            );
            $nodeVerification = is_array($node['evidence_verification'] ?? null) ? $node['evidence_verification'] : [];
            $nodeAttestation = is_array($node['attestation_verification'] ?? null) ? $node['attestation_verification'] : [];
            $nodeTrust = $this->buildNodeEvidenceTrustSummary($nodeVerification, $nodeAttestation, $perspective);
            foreach ($nodeTrust as $key => $value) {
                $summary[$key] = ($summary[$key] ?? 0) + $value;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $verification
     * @param array<string, mixed> $attestation
     * @return array<string, int>
     */
    private function buildNodeEvidenceTrustSummary(array $verification, array $attestation, string $perspective): array
    {
        $summary = [
            'local_verified_persisted' => 0,
            'remote_attested_persisted' => 0,
            'remote_verified_persisted' => 0,
            'legacy_reconstructed' => 0,
            'untrusted_mismatch' => 0,
            'untrusted_attestation_mismatch' => 0,
            'unknown_trust' => 0,
        ];

        $verified = (int) ($verification['verified_persisted_evidence'] ?? 0);
        if ($verified > 0) {
            if ($perspective === 'local_node') {
                $summary['local_verified_persisted'] += $verified;
            } elseif ($perspective === 'federated_remote_node') {
                $attested = min($verified, (int) ($attestation['verified_source_node_attestation'] ?? 0));
                $summary['remote_attested_persisted'] += $attested;
                $summary['remote_verified_persisted'] += max(0, $verified - $attested);
            } else {
                $summary['unknown_trust'] += $verified;
            }
        }

        $summary['legacy_reconstructed'] += (int) ($verification['reconstructed_legacy_evidence'] ?? 0);
        $summary['untrusted_mismatch'] += (int) ($verification['mismatch_persisted_evidence'] ?? 0);
        $summary['untrusted_attestation_mismatch'] += (int) ($attestation['mismatch_source_node_attestation'] ?? 0);
        $summary['unknown_trust'] += (int) ($verification['unknown'] ?? 0);

        return $summary;
    }
}
