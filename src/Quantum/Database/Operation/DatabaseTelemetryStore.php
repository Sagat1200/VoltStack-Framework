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
    ) {
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
     *   latest:list<array<string, scalar|null|array<int, string>>>
     * }
     */
    public function summary(int $limit = 10): array
    {
        $completed = 0;
        $failed = 0;
        $cancelled = 0;
        $slow = 0;

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
        }

        $latest = array_slice($this->entries, -max(1, $limit));

        return [
            'total_operations' => count($this->entries),
            'completed' => $completed,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'slow_queries' => $slow,
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
     * @return array<string, scalar|null|array<int, string>>
     */
    private static function entryToArray(DatabaseOperationPlan $plan, DatabaseDiagnosticSnapshot $snapshot): array
    {
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
        ];
    }
}
