<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

use VoltStack\Runtime\Context\RuntimeContext;

final class DatabaseTelemetryStore
{
    /**
     * @var list<array{plan:DatabaseOperationPlan,snapshot:DatabaseDiagnosticSnapshot,sqg_pipeline?:array<string,mixed>}>
     */
    private array $entries = [];

    /**
     * @var array<string, mixed>
     */
    private array $alertSampling;

    /**
     * @param list<DatabaseCircuitStateSnapshot> $segments
     */
    public function __construct(
        private array $segments = [],
    ) {
        $this->alertSampling = self::emptyAlertSamplingSummary();
    }

    public function record(
        DatabaseOperationPlan $plan,
        DatabaseDiagnosticSnapshot $snapshot,
        DatabaseCircuitStateSnapshot $segmentState,
    ): void {
        $this->entries[] = [
            'plan' => $plan,
            'snapshot' => $snapshot,
        ];

        $this->segments[$segmentState->segment] = $segmentState;
        $this->syncRuntimeContext();
    }

    /**
     * @param array<string, mixed> $pipeline
     */
    public function attachSqgPipeline(string $planFingerprint, array $pipeline): void
    {
        if ($pipeline === []) {
            return;
        }

        for ($index = count($this->entries) - 1; $index >= 0; $index--) {
            $entry = $this->entries[$index] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $plan = $entry['plan'] ?? null;
            if (!$plan instanceof DatabaseOperationPlan || $plan->fingerprint !== $planFingerprint) {
                continue;
            }

            $this->entries[$index]['sqg_pipeline'] = $pipeline;
            $this->syncRuntimeContext();
            return;
        }
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function attachAlertSampling(array $summary): void
    {
        $this->alertSampling = self::normalizeAlertSamplingSummary($summary);
        $this->syncRuntimeContext();
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyAlertSamplingSummary(): array
    {
        return [
            'profile' => null,
            'store' => null,
            'window_seconds' => null,
            'visible_total' => 0,
            'visible_alerts' => [],
            'suppressed_total' => 0,
            'suppressed_alerts' => [],
            'cumulative_visible_total' => 0,
            'cumulative_visible_alerts' => [],
            'cumulative_suppressed_total' => 0,
            'cumulative_suppressed_alerts' => [],
            'by_fingerprint' => [],
            'by_logical_target' => [],
            'pruned_records_total' => 0,
            'last_pruned_records' => 0,
        ];
    }

    public function health(): DatabaseHealthSnapshot
    {
        $segments = array_values($this->segments);
        $closed = 0;
        $halfOpen = 0;
        $open = 0;

        foreach ($segments as $segment) {
            match ($segment->state) {
                'open' => $open++,
                'half_open' => $halfOpen++,
                default => $closed++,
            };
        }

        return new DatabaseHealthSnapshot(
            totalSegments: count($segments),
            closedSegments: $closed,
            halfOpenSegments: $halfOpen,
            openSegments: $open,
            segments: $segments,
        );
    }

    /**
     * @return array{
     *   total_operations:int,
     *   completed:int,
     *   failed:int,
     *   cancelled:int,
     *   slow_queries:int,
     *   remote_replay_challenge:array<string, mixed>,
     *   sqg_pipeline:array<string, mixed>,
     *   alert_sampling:array<string, mixed>,
     *   latest:list<array<string, scalar|null|array<int, string>>>
     * }
     */
    public function summary(int $limit = 10): array
    {
        $completed = 0;
        $failed = 0;
        $cancelled = 0;
        $slow = 0;
        $remoteReplayChallenge = [
            'observed_operations' => 0,
            'verified' => 0,
            'unavailable' => 0,
            'rejected' => 0,
            'reused_receipts' => 0,
            'cleanup_tombstones' => 0,
            'compatible' => 0,
            'incompatible' => 0,
            'protocols' => [],
            'request_key_ids' => [],
            'response_key_ids' => [],
        ];
        $sqgPipeline = [
            'observed_operations' => 0,
            'optimizer_strategies' => [],
            'selected_candidates' => [],
            'planner_logical_roots' => [],
            'planner_physical_roots' => [],
            'join_reorder_selected' => 0,
            'join_reorder_signatures' => [],
            'estimated_cost_total' => 0.0,
            'estimated_cost_avg' => 0.0,
            'estimated_cost_min' => null,
            'estimated_cost_max' => null,
            'cost_delta_vs_baseline_total' => 0.0,
            'cost_delta_vs_baseline_avg' => 0.0,
            'cost_delta_vs_baseline_max' => 0.0,
            'candidate_count_total' => 0,
            'candidate_count_avg' => 0.0,
            'candidate_count_max' => 0,
        ];

        foreach ($this->entries as $entry) {
            $snapshot = $entry['snapshot'];
            if ($snapshot->outcome === 'completed') {
                $completed++;
            } elseif ($snapshot->outcome === 'cancelled') {
                $cancelled++;
            } else {
                $failed++;
            }

            if ($snapshot->slowQuery) {
                $slow++;
            }

            self::collectRemoteReplayChallengeSummary(
                $remoteReplayChallenge,
                self::extractRemoteReplayChallengeTelemetry($snapshot),
            );
            self::collectSqgPipelineSummary(
                $sqgPipeline,
                is_array($entry['sqg_pipeline'] ?? null) ? $entry['sqg_pipeline'] : null,
            );
        }

        if ((int) $sqgPipeline['observed_operations'] > 0) {
            $observedOperations = (int) $sqgPipeline['observed_operations'];
            $sqgPipeline['estimated_cost_avg'] = round(((float) $sqgPipeline['estimated_cost_total']) / $observedOperations, 2);
            $sqgPipeline['cost_delta_vs_baseline_avg'] = round(((float) $sqgPipeline['cost_delta_vs_baseline_total']) / $observedOperations, 2);
            $sqgPipeline['candidate_count_avg'] = round(((int) $sqgPipeline['candidate_count_total']) / $observedOperations, 2);
        }

        $latest = array_slice($this->entries, -max(1, $limit));

        return [
            'total_operations' => count($this->entries),
            'completed' => $completed,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'slow_queries' => $slow,
            'remote_replay_challenge' => $remoteReplayChallenge,
            'sqg_pipeline' => $sqgPipeline,
            'alert_sampling' => $this->alertSampling,
            'latest' => array_values(array_map(
                static fn(array $entry): array => self::entryToArray(
                    $entry['plan'],
                    $entry['snapshot'],
                    is_array($entry['sqg_pipeline'] ?? null) ? $entry['sqg_pipeline'] : null,
                ),
                $latest,
            )),
        ];
    }

    private function syncRuntimeContext(): void
    {
        $runtime = RuntimeContext::current();
        if (!$runtime instanceof RuntimeContext) {
            return;
        }

        $runtime->set('database.telemetry', $this->summary());
        $runtime->set('database.health', $this->health()->toArray());
    }

    /**
     * @return array<string, scalar|null|array<int, string>|array<string, mixed>>
     */
    private static function entryToArray(DatabaseOperationPlan $plan, DatabaseDiagnosticSnapshot $snapshot, ?array $sqgPipeline = null): array
    {
        $challenge = self::extractRemoteReplayChallengeTelemetry($snapshot) ?? [];

        $entry = [
            'fingerprint' => $snapshot->fingerprint,
            'connection_name' => $snapshot->connectionName,
            'driver' => $snapshot->driver,
            'operation_kind' => $plan->operation->kind->value,
            'logical_target' => $plan->logicalTarget,
            'outcome' => $snapshot->outcome,
            'failure' => $snapshot->failure?->value,
            'duration_ms' => $snapshot->durationMs,
            'rows_read' => $snapshot->rowsRead,
            'affected_rows' => $snapshot->affectedRows,
            'slow_query' => $snapshot->slowQuery ? 'yes' : 'no',
            'circuit_state' => $snapshot->circuitState,
            'remote_validation_status' => $challenge['remote_validation_status'] ?? null,
            'remote_validation_validator' => $challenge['remote_validation_validator'] ?? null,
            'challenge_protocol' => $challenge['challenge_protocol'] ?? null,
            'challenge_compatibility' => $challenge['challenge_compatibility'] ?? null,
            'challenge_request_key_id' => $challenge['challenge_request_key_id'] ?? null,
            'challenge_response_key_id' => $challenge['challenge_response_key_id'] ?? null,
            'challenge_receipt_reuse' => $challenge['challenge_receipt_reuse'] ?? null,
            'challenge_receipt_reuse_scope' => $challenge['challenge_receipt_reuse_scope'] ?? null,
            'challenge_receipt_validated_by_node_id' => $challenge['challenge_receipt_validated_by_node_id'] ?? null,
            'challenge_receipt_attestation_verification' => $challenge['challenge_receipt_attestation_verification'] ?? null,
            'challenge_receipt_attestation_key_id' => $challenge['challenge_receipt_attestation_key_id'] ?? null,
            'challenge_receipt_advertisement' => is_array($challenge['challenge_receipt_advertisement'] ?? null)
                ? $challenge['challenge_receipt_advertisement']
                : null,
            'challenge_receipt_tombstone_advertisement' => is_array($challenge['challenge_receipt_tombstone_advertisement'] ?? null)
                ? $challenge['challenge_receipt_tombstone_advertisement']
                : null,
        ];

        if (is_array($sqgPipeline) && $sqgPipeline !== []) {
            $entry['sqg_pipeline'] = $sqgPipeline;
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, string|int|array<string, mixed>|null>|null $telemetry
     */
    private static function collectRemoteReplayChallengeSummary(array &$summary, ?array $telemetry): void
    {
        if ($telemetry === null) {
            return;
        }

        $summary['observed_operations']++;

        $status = self::normalizeString($telemetry['remote_validation_status'] ?? null);
        match ($status) {
            'verified_remote_validation' => $summary['verified']++,
            'remote_validation_unavailable' => $summary['unavailable']++,
            'remote_validation_rejected' => $summary['rejected']++,
            default => null,
        };

        $compatibility = self::normalizeString($telemetry['challenge_compatibility'] ?? null);
        match ($compatibility) {
            'compatible' => $summary['compatible']++,
            'incompatible' => $summary['incompatible']++,
            default => null,
        };

        if (self::normalizeString($telemetry['challenge_receipt_reuse'] ?? null) === 'reused_fresh_receipt') {
            $summary['reused_receipts']++;
        }
        if (is_array($telemetry['challenge_receipt_tombstone_advertisement'] ?? null)) {
            $summary['cleanup_tombstones']++;
        }

        self::incrementCountMap($summary['protocols'], self::normalizeString($telemetry['challenge_protocol'] ?? null));
        self::incrementCountMap($summary['request_key_ids'], self::normalizeString($telemetry['challenge_request_key_id'] ?? null));
        self::incrementCountMap($summary['response_key_ids'], self::normalizeString($telemetry['challenge_response_key_id'] ?? null));
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $pipeline
     */
    private static function collectSqgPipelineSummary(array &$summary, ?array $pipeline): void
    {
        if ($pipeline === null) {
            return;
        }

        $summary['observed_operations']++;

        $optimizer = is_array($pipeline['optimizer'] ?? null) ? $pipeline['optimizer'] : [];
        $planner = is_array($pipeline['planner'] ?? null) ? $pipeline['planner'] : [];

        self::incrementCountMap($summary['optimizer_strategies'], self::normalizeString($optimizer['strategy'] ?? null));
        self::incrementCountMap($summary['selected_candidates'], self::normalizeString($optimizer['selected_candidate_id'] ?? null));
        self::incrementCountMap($summary['planner_logical_roots'], self::normalizeString($planner['logical_root_operator'] ?? null));
        self::incrementCountMap($summary['planner_physical_roots'], self::normalizeString($planner['physical_root_strategy'] ?? null));

        $estimatedCost = self::normalizeFloat($optimizer['estimated_cost'] ?? null);
        if ($estimatedCost !== null) {
            $summary['estimated_cost_total'] = round(((float) $summary['estimated_cost_total']) + $estimatedCost, 2);
            $summary['estimated_cost_min'] = $summary['estimated_cost_min'] === null
                ? $estimatedCost
                : min((float) $summary['estimated_cost_min'], $estimatedCost);
            $summary['estimated_cost_max'] = $summary['estimated_cost_max'] === null
                ? $estimatedCost
                : max((float) $summary['estimated_cost_max'], $estimatedCost);
        }

        $costDelta = self::normalizeFloat($optimizer['cost_delta_vs_baseline'] ?? null);
        if ($costDelta !== null) {
            $summary['cost_delta_vs_baseline_total'] = round(((float) $summary['cost_delta_vs_baseline_total']) + $costDelta, 2);
            $summary['cost_delta_vs_baseline_max'] = max((float) ($summary['cost_delta_vs_baseline_max'] ?? 0.0), $costDelta);
        }

        $candidateCount = self::normalizeInt($optimizer['candidate_count'] ?? null);
        if ($candidateCount !== null) {
            $summary['candidate_count_total'] = (int) $summary['candidate_count_total'] + $candidateCount;
            $summary['candidate_count_max'] = max((int) ($summary['candidate_count_max'] ?? 0), $candidateCount);
        }

        $joinReorder = is_array($optimizer['join_reorder'] ?? null) ? $optimizer['join_reorder'] : [];
        $selectedSignature = self::normalizeString($joinReorder['selected_signature'] ?? null);
        if ($selectedSignature !== null) {
            $summary['join_reorder_selected'] = (int) ($summary['join_reorder_selected'] ?? 0) + 1;
            self::incrementCountMap($summary['join_reorder_signatures'], $selectedSignature);
        }
    }

    /**
     * @return array<string, string|int|array<string, mixed>|null>|null
     */
    private static function extractRemoteReplayChallengeTelemetry(DatabaseDiagnosticSnapshot $snapshot): ?array
    {
        $telemetry = [];

        foreach ($snapshot->events as $event) {
            if (!$event instanceof DatabaseDiagnosticEvent) {
                continue;
            }

            $details = $event->details;
            if (!is_array($details) || $details === []) {
                continue;
            }

            $relevant = false;
            foreach (
                [
                    'remote_validation_status',
                    'remote_validation_validator',
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
                    'receipt_attestation_key_id',
                    'receipt_advertisement',
                    'receipt_tombstone_advertisement',
                ] as $key
            ) {
                if (array_key_exists($key, $details)) {
                    $relevant = true;
                    break;
                }
            }

            if (!$relevant) {
                continue;
            }

            $telemetry['remote_validation_status'] = self::normalizeString($details['remote_validation_status'] ?? null)
                ?? ($telemetry['remote_validation_status'] ?? null);
            $telemetry['remote_validation_validator'] = self::normalizeString($details['remote_validation_validator'] ?? null)
                ?? ($telemetry['remote_validation_validator'] ?? null);
            $telemetry['challenge_protocol'] = self::normalizeString(
                $details['protocol_negotiated']
                    ?? $details['response_protocol']
                    ?? $details['challenge_protocol']
                    ?? $details['protocol']
                    ?? null,
            ) ?? ($telemetry['challenge_protocol'] ?? null);
            $telemetry['challenge_compatibility'] = self::normalizeString($details['protocol_compatibility'] ?? null)
                ?? ($telemetry['challenge_compatibility'] ?? null);
            $telemetry['challenge_request_key_id'] = self::normalizeString($details['request_key_id'] ?? null)
                ?? ($telemetry['challenge_request_key_id'] ?? null);
            $telemetry['challenge_response_key_id'] = self::normalizeString($details['response_key_id'] ?? ($details['key_id'] ?? null))
                ?? ($telemetry['challenge_response_key_id'] ?? null);
            $telemetry['challenge_receipt_reuse'] = self::normalizeString($details['receipt_reuse'] ?? null)
                ?? ($telemetry['challenge_receipt_reuse'] ?? null);
            $telemetry['challenge_receipt_reuse_scope'] = self::normalizeString($details['receipt_reuse_scope'] ?? null)
                ?? ($telemetry['challenge_receipt_reuse_scope'] ?? null);
            $telemetry['challenge_receipt_validated_by_node_id'] = self::normalizeString($details['receipt_validated_by_node_id'] ?? null)
                ?? ($telemetry['challenge_receipt_validated_by_node_id'] ?? null);
            $telemetry['challenge_receipt_attestation_verification'] = self::normalizeString($details['receipt_attestation_verification'] ?? null)
                ?? ($telemetry['challenge_receipt_attestation_verification'] ?? null);
            $telemetry['challenge_receipt_attestation_key_id'] = self::normalizeString($details['receipt_attestation_key_id'] ?? null)
                ?? ($telemetry['challenge_receipt_attestation_key_id'] ?? null);
            $telemetry['challenge_receipt_advertisement'] = is_array($details['receipt_advertisement'] ?? null)
                ? $details['receipt_advertisement']
                : ($telemetry['challenge_receipt_advertisement'] ?? null);
            $telemetry['challenge_receipt_tombstone_advertisement'] = is_array($details['receipt_tombstone_advertisement'] ?? null)
                ? $details['receipt_tombstone_advertisement']
                : ($telemetry['challenge_receipt_tombstone_advertisement'] ?? null);
        }

        return $telemetry === [] ? null : $telemetry;
    }

    /**
     * @param array<string, int> $counts
     */
    private static function incrementCountMap(array &$counts, ?string $key): void
    {
        if ($key === null) {
            return;
        }

        $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
    }

    private static function normalizeString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function normalizeInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private static function normalizeAlertSamplingSummary(array $summary): array
    {
        $normalized = self::emptyAlertSamplingSummary();

        $normalized['profile'] = self::normalizeString($summary['profile'] ?? null);
        $normalized['store'] = self::normalizeString($summary['store'] ?? null);
        $normalized['window_seconds'] = self::normalizeInt($summary['window_seconds'] ?? null);
        $normalized['visible_total'] = max(0, (int) ($summary['visible_total'] ?? 0));
        $normalized['visible_alerts'] = is_array($summary['visible_alerts'] ?? null) ? $summary['visible_alerts'] : [];
        $normalized['suppressed_total'] = max(0, (int) ($summary['suppressed_total'] ?? 0));
        $normalized['suppressed_alerts'] = is_array($summary['suppressed_alerts'] ?? null) ? $summary['suppressed_alerts'] : [];
        $normalized['cumulative_visible_total'] = max(0, (int) ($summary['cumulative_visible_total'] ?? 0));
        $normalized['cumulative_visible_alerts'] = is_array($summary['cumulative_visible_alerts'] ?? null)
            ? $summary['cumulative_visible_alerts']
            : [];
        $normalized['cumulative_suppressed_total'] = max(0, (int) ($summary['cumulative_suppressed_total'] ?? 0));
        $normalized['cumulative_suppressed_alerts'] = is_array($summary['cumulative_suppressed_alerts'] ?? null)
            ? $summary['cumulative_suppressed_alerts']
            : [];
        $normalized['by_fingerprint'] = is_array($summary['by_fingerprint'] ?? null) ? $summary['by_fingerprint'] : [];
        $normalized['by_logical_target'] = is_array($summary['by_logical_target'] ?? null) ? $summary['by_logical_target'] : [];
        $normalized['pruned_records_total'] = max(0, (int) ($summary['pruned_records_total'] ?? 0));
        $normalized['last_pruned_records'] = max(0, (int) ($summary['last_pruned_records'] ?? 0));

        return $normalized;
    }
}
