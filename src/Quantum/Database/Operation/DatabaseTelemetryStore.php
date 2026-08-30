<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

use VoltStack\Runtime\Context\RuntimeContext;

final class DatabaseTelemetryStore
{
    /**
     * @var list<array{plan:DatabaseOperationPlan,snapshot:DatabaseDiagnosticSnapshot}>
     */
    private array $entries = [];

    /**
     * @param list<DatabaseCircuitStateSnapshot> $segments
     */
    public function __construct(
        private array $segments = [],
    ) {}

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
        }

        $latest = array_slice($this->entries, -max(1, $limit));

        return [
            'total_operations' => count($this->entries),
            'completed' => $completed,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'slow_queries' => $slow,
            'remote_replay_challenge' => $remoteReplayChallenge,
            'latest' => array_values(array_map(
                static fn(array $entry): array => self::entryToArray($entry['plan'], $entry['snapshot']),
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
    private static function entryToArray(DatabaseOperationPlan $plan, DatabaseDiagnosticSnapshot $snapshot): array
    {
        $challenge = self::extractRemoteReplayChallengeTelemetry($snapshot) ?? [];

        return [
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
}
