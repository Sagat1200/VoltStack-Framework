<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Engine\DatabaseTelemetrySignalMapper;

final class DatabaseTelemetrySignalMapperTest extends TestCase
{
    public function test_it_maps_database_report_to_canonical_telemetry_signal(): void
    {
        $mapper = new DatabaseTelemetrySignalMapper();

        $signal = $mapper->map(new DatabaseTelemetryReport(
            requestId: 'req-db-1',
            tenantId: 'tenant-a',
            traceId: 'trace-db-1',
            generatedAt: '2026-08-29T11:00:00+00:00',
            summary: [
                'total_operations' => 3,
                'completed' => 2,
                'failed' => 1,
                'cancelled' => 0,
                'slow_queries' => 1,
                'remote_replay_challenge' => [
                    'observed_operations' => 2,
                    'verified' => 1,
                    'unavailable' => 1,
                    'rejected' => 1,
                    'compatible' => 1,
                    'incompatible' => 1,
                    'protocols' => ['remote_replay_node_challenge_v1' => 2],
                    'request_key_ids' => ['key-2026-08' => 1],
                    'response_key_ids' => ['key-2026-09' => 1],
                ],
                'sqg_pipeline' => [
                    'observed_operations' => 1,
                    'join_reorder_selected' => 1,
                    'optimizer_strategies' => ['safe_rule_bundle_v1' => 1],
                    'selected_candidates' => ['candidate:predicate_pushdown_v1+join_reorder_v1' => 1],
                    'planner_logical_roots' => ['sort' => 1],
                    'planner_physical_roots' => ['sort_materialize' => 1],
                ],
            ],
            health: [
                'total_segments' => 2,
                'closed_segments' => 1,
                'half_open_segments' => 1,
                'open_segments' => 0,
            ],
            nodeId: 'node-a',
        ));

        self::assertSame('database_telemetry', $signal->name);
        self::assertSame('report', $signal->type);
        self::assertSame('database', $signal->source);
        self::assertSame('req-db-1', $signal->requestId);
        self::assertSame(3, $signal->payload['summary']['total_operations']);
        self::assertSame(2, $signal->attributes['health']['total_segments']);
        self::assertSame(1, $signal->attributes['sqg_pipeline']['observed_operations']);
        self::assertSame(1, $signal->attributes['sqg_pipeline']['join_reorder_selected']);
        self::assertSame(1, $signal->attributes['sqg_pipeline']['optimizer_strategies']['safe_rule_bundle_v1']);
        self::assertCount(3, $signal->alerts);
        self::assertSame('database.remote_replay_challenge.incompatible', $signal->alerts[0]['name']);
    }
}
