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

            $lookupKey = $this->resolveLookupKeyHash($input);
            if ($lookupKey !== null) {
                $record = $store->find($lookupKey);

                if (!$record instanceof DatabaseIdempotencyRecord) {
                    $output->writeln('No hay reserva de idempotencia para esa key.');
                    return 0;
                }

                return $this->renderRecord($record, $currentNodeId, $input, $output);
            }

            if ($input->hasOption('aggregate')) {
                $aggregate = $store->aggregate($limit);
                $aggregate['current_node_id'] = $currentNodeId;
                $aggregate['node_perspective'] = $this->buildAggregateNodePerspective($aggregate, $currentNodeId);

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
                $nodesDetail = is_array($aggregate['nodes_detail'] ?? null) ? $aggregate['nodes_detail'] : [];
                $nodePerspective = is_array($aggregate['node_perspective'] ?? null) ? $aggregate['node_perspective'] : [];
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
                    'Perspective: current_node=%s local_records=%d remote_records=%d unknown_records=%d',
                    $currentNodeId ?? 'n/a',
                    (int) ($nodePerspective['local_records'] ?? 0),
                    (int) ($nodePerspective['remote_records'] ?? 0),
                    (int) ($nodePerspective['unknown_records'] ?? 0),
                ));
                foreach ($nodesDetail as $node) {
                    if (!is_array($node)) {
                        continue;
                    }

                    $nodeStatuses = is_array($node['statuses'] ?? null) ? $node['statuses'] : [];
                    $nodeReplaySupport = is_array($node['replay_support'] ?? null) ? $node['replay_support'] : [];
                    $nodePerspectiveKind = $this->resolveNodePerspective(
                        isset($node['node_id']) ? (string) $node['node_id'] : null,
                        $currentNodeId,
                    );
                    $output->writeln(sprintf(
                        'Node: %s perspective=%s records=%d completed=%d failed=%d pending=%d persisted_summary=%d legacy_reconstructed=%d warning_candidates=%d latest_created_at=%s',
                        (string) ($node['node_id'] ?? 'unknown-node'),
                        $nodePerspectiveKind,
                        (int) ($node['records'] ?? 0),
                        (int) ($nodeStatuses['completed'] ?? 0),
                        (int) ($nodeStatuses['failed'] ?? 0),
                        (int) ($nodeStatuses['pending'] ?? 0),
                        (int) ($nodeReplaySupport['persisted_summary'] ?? 0),
                        (int) ($nodeReplaySupport['legacy_reconstructed'] ?? 0),
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

            return $this->renderRecord($record, $currentNodeId, $input, $output);
        } catch (\Throwable $e) {
            $output->error(sprintf('db:idempotency failed: %s', $e->getMessage()));
            return 1;
        }
    }

    private function renderRecord(DatabaseIdempotencyRecord $record, ?string $currentNodeId, Input $input, Output $output): int
    {
        $replayOrigin = $this->resolveNodePerspective($record->nodeId, $currentNodeId);
        if ($input->hasOption('json')) {
            $payload = $record->toArray();
            $payload['current_node_id'] = $currentNodeId;
            $payload['replay_origin'] = $replayOrigin;
            $payload['confirmation_evidence'] = $this->resolveConfirmationEvidence($record);

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
            $confirmationEvidence = $this->resolveConfirmationEvidence($record);
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
            $replayWarning = $this->resolveReplaySupportWarning($record->confirmation);
            if ($replayWarning !== null) {
                $output->writeln(sprintf('Warning: %s', $replayWarning));
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
    private function resolveConfirmationEvidence(DatabaseIdempotencyRecord $record): array
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
            ];
        }

        return [
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
        ];
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
}
