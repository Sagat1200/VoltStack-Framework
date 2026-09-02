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
                    'join_reorder_signatures' => ['u>p>a>o' => 1],
                    'estimated_cost_total' => 78.5,
                    'estimated_cost_avg' => 78.5,
                    'estimated_cost_min' => 78.5,
                    'estimated_cost_max' => 78.5,
                    'cost_delta_vs_baseline_total' => 2.25,
                    'cost_delta_vs_baseline_avg' => 2.25,
                    'cost_delta_vs_baseline_max' => 2.25,
                    'candidate_count_total' => 2,
                    'candidate_count_avg' => 2.0,
                    'candidate_count_max' => 2,
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
        self::assertSame(78.5, $signal->attributes['sqg_pipeline']['estimated_cost_total']);
        self::assertSame(2.25, $signal->attributes['sqg_pipeline']['cost_delta_vs_baseline_total']);
        self::assertSame(2, $signal->attributes['sqg_pipeline']['candidate_count_total']);
        self::assertSame(1, $signal->attributes['sqg_pipeline']['optimizer_strategies']['safe_rule_bundle_v1']);
        self::assertSame(1, $signal->attributes['sqg_pipeline']['join_reorder_signatures']['u>p>a>o']);
        self::assertCount(3, $signal->alerts);
        self::assertSame('database.remote_replay_challenge.incompatible', $signal->alerts[0]['name']);
    }

    public function test_it_emits_sqg_optimizer_alerts_when_search_grows_without_gain(): void
    {
        $mapper = new DatabaseTelemetrySignalMapper();

        $signal = $mapper->map(new DatabaseTelemetryReport(
            requestId: 'req-db-sqg-alerts',
            tenantId: 'tenant-a',
            traceId: 'trace-db-sqg-alerts',
            generatedAt: '2026-08-31T18:00:00+00:00',
            summary: [
                'total_operations' => 2,
                'completed' => 2,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
                'remote_replay_challenge' => [],
                'sqg_pipeline' => [
                    'observed_operations' => 2,
                    'join_reorder_selected' => 1,
                    'join_reorder_signatures' => ['u>p>a>o' => 1],
                    'estimated_cost_total' => 150.0,
                    'estimated_cost_avg' => 75.0,
                    'estimated_cost_min' => 70.0,
                    'estimated_cost_max' => 80.0,
                    'cost_delta_vs_baseline_total' => 0.0,
                    'cost_delta_vs_baseline_avg' => 0.0,
                    'cost_delta_vs_baseline_max' => 0.0,
                    'candidate_count_total' => 8,
                    'candidate_count_avg' => 4.0,
                    'candidate_count_max' => 4,
                    'optimizer_strategies' => ['safe_rule_bundle_v1' => 2],
                    'selected_candidates' => ['candidate:predicate_normalization_v1+join_reorder_v1' => 2],
                    'planner_logical_roots' => ['sort' => 2],
                    'planner_physical_roots' => ['sort_materialize' => 2],
                ],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
            ],
            nodeId: 'node-a',
        ));

        self::assertCount(3, $signal->alerts);
        self::assertSame('database.sqg_pipeline.optimizer.wide_search', $signal->alerts[0]['name']);
        self::assertSame('database.sqg_pipeline.optimizer.no_gain', $signal->alerts[1]['name']);
        self::assertSame('database.sqg_pipeline.join_reorder.no_gain', $signal->alerts[2]['name']);
        self::assertSame('warning', $signal->alerts[0]['severity']);
        self::assertSame(4, $signal->alerts[0]['count']);
        self::assertSame(8, $signal->alerts[1]['count']);
        self::assertSame(1, $signal->alerts[2]['count']);
        self::assertSame(4, $signal->alerts[0]['context']['threshold_candidate_count_max'] ?? null);
        self::assertSame(3.0, $signal->alerts[0]['context']['threshold_candidate_count_avg'] ?? null);
        self::assertSame(0.0, $signal->alerts[1]['context']['threshold_cost_delta_max'] ?? null);
    }

    public function test_it_honors_custom_sqg_alert_thresholds(): void
    {
        $mapper = new DatabaseTelemetrySignalMapper([
            'wide_search_candidate_count_max' => 10,
            'wide_search_candidate_count_avg' => 9.0,
            'no_gain_cost_delta_max' => -1.0,
        ]);

        $signal = $mapper->map(new DatabaseTelemetryReport(
            requestId: 'req-db-custom-thresholds',
            tenantId: 'tenant-a',
            traceId: 'trace-db-custom-thresholds',
            generatedAt: '2026-08-31T18:10:00+00:00',
            summary: [
                'total_operations' => 2,
                'completed' => 2,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
                'remote_replay_challenge' => [],
                'sqg_pipeline' => [
                    'observed_operations' => 2,
                    'join_reorder_selected' => 1,
                    'join_reorder_signatures' => ['u>p>a>o' => 1],
                    'estimated_cost_total' => 150.0,
                    'estimated_cost_avg' => 75.0,
                    'estimated_cost_min' => 70.0,
                    'estimated_cost_max' => 80.0,
                    'cost_delta_vs_baseline_total' => 0.0,
                    'cost_delta_vs_baseline_avg' => 0.0,
                    'cost_delta_vs_baseline_max' => 0.0,
                    'candidate_count_total' => 8,
                    'candidate_count_avg' => 4.0,
                    'candidate_count_max' => 4,
                    'optimizer_strategies' => ['safe_rule_bundle_v1' => 2],
                    'selected_candidates' => ['candidate:predicate_normalization_v1+join_reorder_v1' => 2],
                    'planner_logical_roots' => ['sort' => 2],
                    'planner_physical_roots' => ['sort_materialize' => 2],
                ],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
            ],
            nodeId: 'node-a',
        ));

        self::assertSame([], $signal->alerts);
    }

    public function test_it_honors_custom_sqg_alert_severities(): void
    {
        $mapper = new DatabaseTelemetrySignalMapper(
            sqgPipelineAlertSeverities: [
                'database.sqg_pipeline.optimizer.wide_search' => 'info',
                'database.sqg_pipeline.optimizer.no_gain' => 'high',
                'database.sqg_pipeline.join_reorder.no_gain' => 'critical',
            ],
        );

        $signal = $mapper->map(new DatabaseTelemetryReport(
            requestId: 'req-db-custom-severities',
            tenantId: 'tenant-a',
            traceId: 'trace-db-custom-severities',
            generatedAt: '2026-08-31T19:10:00+00:00',
            summary: [
                'total_operations' => 2,
                'completed' => 2,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
                'remote_replay_challenge' => [],
                'sqg_pipeline' => [
                    'observed_operations' => 2,
                    'join_reorder_selected' => 1,
                    'join_reorder_signatures' => ['u>p>a>o' => 1],
                    'estimated_cost_total' => 150.0,
                    'estimated_cost_avg' => 75.0,
                    'estimated_cost_min' => 70.0,
                    'estimated_cost_max' => 80.0,
                    'cost_delta_vs_baseline_total' => 0.0,
                    'cost_delta_vs_baseline_avg' => 0.0,
                    'cost_delta_vs_baseline_max' => 0.0,
                    'candidate_count_total' => 8,
                    'candidate_count_avg' => 4.0,
                    'candidate_count_max' => 4,
                    'optimizer_strategies' => ['safe_rule_bundle_v1' => 2],
                    'selected_candidates' => ['candidate:predicate_normalization_v1+join_reorder_v1' => 2],
                    'planner_logical_roots' => ['sort' => 2],
                    'planner_physical_roots' => ['sort_materialize' => 2],
                ],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
            ],
            nodeId: 'node-a',
        ));

        self::assertCount(3, $signal->alerts);
        self::assertSame('info', $signal->alerts[0]['severity']);
        self::assertSame('high', $signal->alerts[1]['severity']);
        self::assertSame('critical', $signal->alerts[2]['severity']);
    }

    public function test_it_describes_potential_sqg_alerts_for_a_single_operation_pipeline(): void
    {
        $mapper = new DatabaseTelemetrySignalMapper();

        $descriptions = $mapper->describeSqgOperationAlerts([
            'optimizer' => [
                'candidate_count' => 4,
                'cost_delta_vs_baseline' => 0.0,
                'selected_candidate_id' => 'candidate:predicate_normalization_v1+join_reorder_v1',
                'join_reorder' => [
                    'selected_signature' => 'u>p>a>o',
                ],
            ],
        ]);

        self::assertCount(3, $descriptions);
        self::assertSame('database.sqg_pipeline.optimizer.wide_search', $descriptions[0]['name']);
        self::assertSame('database.sqg_pipeline.optimizer.no_gain', $descriptions[1]['name']);
        self::assertSame('database.sqg_pipeline.join_reorder.no_gain', $descriptions[2]['name']);
        self::assertSame(4, $descriptions[0]['context']['candidate_count'] ?? null);
        self::assertSame('u>p>a>o', $descriptions[2]['context']['selected_signature'] ?? null);
    }
}
