<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Engine\DatabaseTelemetrySignalAlertSampler;
use Quantum\Database\Operation\Engine\DatabaseTelemetrySignalMapper;
use Quantum\Database\Operation\Engine\OpenTelemetryDatabaseTelemetryDispatcher;

final class OpenTelemetryDatabaseTelemetryDispatcherTest extends TestCase
{
    public function test_it_posts_database_telemetry_as_otlp_log_payload(): void
    {
        $captured = [];
        $dispatcher = new OpenTelemetryDatabaseTelemetryDispatcher(
            endpoint: 'https://collector.internal/v1/logs',
            serviceName: 'voltstack-db',
            serviceNamespace: 'voltstack.database',
            scopeName: 'voltstack.database',
            scopeVersion: '1.0.0',
            headers: [
                'Authorization' => 'Bearer otel-token',
            ],
            requestTimeoutMs: 3200,
            sender: function (string $endpoint, array $payload, array $headers, int $timeoutMs) use (&$captured): array {
                $captured = [
                    'endpoint' => $endpoint,
                    'payload' => $payload,
                    'headers' => $headers,
                    'timeout_ms' => $timeoutMs,
                ];

                return [
                    'status' => 202,
                    'headers' => [],
                    'body' => '{"partialSuccess":{}}',
                ];
            },
        );

        $dispatcher->dispatch(new DatabaseTelemetryReport(
            requestId: 'req-otel-1',
            tenantId: 'tenant-a',
            traceId: 'trace-otel-1',
            generatedAt: '2026-08-29T19:00:00+00:00',
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
                    'reused_receipts' => 0,
                    'compatible' => 1,
                    'incompatible' => 1,
                    'protocols' => [
                        'remote_replay_node_challenge_v1' => 2,
                    ],
                    'request_key_ids' => [
                        'key-2026-08' => 1,
                    ],
                    'response_key_ids' => [
                        'key-2026-09' => 1,
                    ],
                ],
                'sqg_pipeline' => [
                    'observed_operations' => 1,
                    'join_reorder_selected' => 1,
                    'join_reorder_signatures' => [
                        'u>p>a>o' => 1,
                    ],
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
                    'optimizer_strategies' => [
                        'safe_rule_bundle_v1' => 1,
                    ],
                    'selected_candidates' => [
                        'candidate:predicate_normalization_v1+join_reorder_v1' => 1,
                    ],
                    'planner_logical_roots' => [
                        'sort' => 1,
                    ],
                    'planner_physical_roots' => [
                        'sort_materialize' => 1,
                    ],
                ],
                'latest' => [],
            ],
            health: [
                'total_segments' => 2,
                'closed_segments' => 1,
                'half_open_segments' => 1,
                'open_segments' => 0,
                'segments' => [],
            ],
            nodeId: 'node-a',
        ));

        self::assertSame('https://collector.internal/v1/logs', $captured['endpoint']);
        self::assertSame('Bearer otel-token', $captured['headers']['Authorization']);
        self::assertSame(3200, $captured['timeout_ms']);

        $resourceLogs = $captured['payload']['resourceLogs'][0] ?? null;
        self::assertIsArray($resourceLogs);

        $resourceAttributes = $this->attributesToMap($resourceLogs['resource']['attributes'] ?? []);
        self::assertSame('voltstack-db', $resourceAttributes['service.name'] ?? null);
        self::assertSame('voltstack.database', $resourceAttributes['service.namespace'] ?? null);
        self::assertSame('database', $resourceAttributes['voltstack.telemetry.source'] ?? null);

        $record = $resourceLogs['scopeLogs'][0]['logRecords'][0] ?? null;
        self::assertIsArray($record);
        self::assertSame('ERROR', $record['severityText'] ?? null);
        self::assertStringContainsString('"request_id":"req-otel-1"', $record['body']['stringValue'] ?? '');

        $attributes = $this->attributesToMap($record['attributes'] ?? []);
        self::assertSame('req-otel-1', $attributes['db.request_id'] ?? null);
        self::assertSame('tenant-a', $attributes['db.tenant_id'] ?? null);
        self::assertSame('3', $attributes['db.summary.total_operations'] ?? null);
        self::assertSame('1', $attributes['db.summary.remote_replay_challenge.incompatible'] ?? null);
        self::assertSame('1', $attributes['db.summary.sqg_pipeline.observed_operations'] ?? null);
        self::assertSame('1', $attributes['db.attributes.sqg_pipeline.join_reorder_selected'] ?? null);
        self::assertSame(78.5, $attributes['db.attributes.sqg_pipeline.estimated_cost_total'] ?? null);
        self::assertSame(2.25, $attributes['db.attributes.sqg_pipeline.cost_delta_vs_baseline_total'] ?? null);
        self::assertSame('2', $attributes['db.attributes.sqg_pipeline.candidate_count_total'] ?? null);
        self::assertSame('1', $attributes['db.attributes.sqg_pipeline.optimizer_strategies.safe_rule_bundle_v1'] ?? null);
        self::assertSame('1', $attributes['db.attributes.sqg_pipeline.join_reorder_signatures.u>p>a>o'] ?? null);
        self::assertSame('database.remote_replay_challenge.incompatible', $attributes['db.alerts.0.name'] ?? null);
    }

    public function test_it_exports_sqg_optimizer_alerts_as_warn_severity_when_no_higher_alert_exists(): void
    {
        $captured = [];
        $dispatcher = new OpenTelemetryDatabaseTelemetryDispatcher(
            endpoint: 'https://collector.internal/v1/logs',
            sender: function (string $endpoint, array $payload, array $headers, int $timeoutMs) use (&$captured): array {
                $captured = $payload;

                return [
                    'status' => 202,
                    'headers' => [],
                    'body' => '{"partialSuccess":{}}',
                ];
            },
        );

        $dispatcher->dispatch(new DatabaseTelemetryReport(
            requestId: 'req-otel-sqg-alerts',
            tenantId: 'tenant-a',
            traceId: 'trace-otel-sqg-alerts',
            generatedAt: '2026-08-31T18:05:00+00:00',
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
                'latest' => [],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
                'segments' => [],
            ],
            nodeId: 'node-a',
        ));

        $record = $captured['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0] ?? null;
        self::assertIsArray($record);
        self::assertSame('WARN', $record['severityText'] ?? null);

        $attributes = $this->attributesToMap($record['attributes'] ?? []);
        self::assertSame('database.sqg_pipeline.optimizer.wide_search', $attributes['db.alerts.0.name'] ?? null);
        self::assertSame('database.sqg_pipeline.optimizer.no_gain', $attributes['db.alerts.1.name'] ?? null);
        self::assertSame('database.sqg_pipeline.join_reorder.no_gain', $attributes['db.alerts.2.name'] ?? null);
    }

    public function test_it_honors_injected_mapper_thresholds_when_exporting(): void
    {
        $captured = [];
        $dispatcher = new OpenTelemetryDatabaseTelemetryDispatcher(
            endpoint: 'https://collector.internal/v1/logs',
            mapper: new DatabaseTelemetrySignalMapper([
                'wide_search_candidate_count_max' => 10,
                'wide_search_candidate_count_avg' => 9.0,
                'no_gain_cost_delta_max' => -1.0,
            ]),
            sender: function (string $endpoint, array $payload, array $headers, int $timeoutMs) use (&$captured): array {
                $captured = $payload;

                return [
                    'status' => 202,
                    'headers' => [],
                    'body' => '{"partialSuccess":{}}',
                ];
            },
        );

        $dispatcher->dispatch(new DatabaseTelemetryReport(
            requestId: 'req-otel-custom-thresholds',
            tenantId: 'tenant-a',
            traceId: 'trace-otel-custom-thresholds',
            generatedAt: '2026-08-31T18:15:00+00:00',
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
                    'latest' => [],
                ],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
                'segments' => [],
            ],
            nodeId: 'node-a',
        ));

        $record = $captured['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0] ?? null;
        self::assertIsArray($record);
        self::assertSame('INFO', $record['severityText'] ?? null);

        $attributes = $this->attributesToMap($record['attributes'] ?? []);
        self::assertArrayNotHasKey('db.alerts.0.name', $attributes);
    }

    public function test_it_exports_error_severity_when_sqg_alert_profile_escalates_to_high(): void
    {
        $captured = [];
        $dispatcher = new OpenTelemetryDatabaseTelemetryDispatcher(
            endpoint: 'https://collector.internal/v1/logs',
            mapper: new DatabaseTelemetrySignalMapper(
                sqgPipelineAlertSeverities: [
                    'database.sqg_pipeline.optimizer.wide_search' => 'warning',
                    'database.sqg_pipeline.optimizer.no_gain' => 'high',
                    'database.sqg_pipeline.join_reorder.no_gain' => 'high',
                ],
            ),
            sender: function (string $endpoint, array $payload, array $headers, int $timeoutMs) use (&$captured): array {
                $captured = $payload;

                return [
                    'status' => 202,
                    'headers' => [],
                    'body' => '{"partialSuccess":{}}',
                ];
            },
        );

        $dispatcher->dispatch(new DatabaseTelemetryReport(
            requestId: 'req-otel-severity-profile',
            tenantId: 'tenant-a',
            traceId: 'trace-otel-severity-profile',
            generatedAt: '2026-08-31T19:15:00+00:00',
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
                    'latest' => [],
                ],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
                'segments' => [],
            ],
            nodeId: 'node-a',
        ));

        $record = $captured['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0] ?? null;
        self::assertIsArray($record);
        self::assertSame('ERROR', $record['severityText'] ?? null);

        $attributes = $this->attributesToMap($record['attributes'] ?? []);
        self::assertSame('high', $attributes['db.alerts.1.severity'] ?? null);
        self::assertSame('high', $attributes['db.alerts.2.severity'] ?? null);
    }

    public function test_it_suppresses_repeated_sqg_warning_alerts_before_otlp_export(): void
    {
        $captured = [];
        $dispatcher = new OpenTelemetryDatabaseTelemetryDispatcher(
            endpoint: 'https://collector.internal/v1/logs',
            alertSampler: new DatabaseTelemetrySignalAlertSampler([
                'database.sqg_pipeline.optimizer.wide_search' => 3,
                'database.sqg_pipeline.optimizer.no_gain' => 3,
                'database.sqg_pipeline.join_reorder.no_gain' => 3,
            ]),
            sender: function (string $endpoint, array $payload, array $headers, int $timeoutMs) use (&$captured): array {
                $captured[] = $payload;

                return [
                    'status' => 202,
                    'headers' => [],
                    'body' => '{"partialSuccess":{}}',
                ];
            },
        );

        $report = new DatabaseTelemetryReport(
            requestId: 'req-otel-sampled-alerts',
            tenantId: 'tenant-a',
            traceId: 'trace-otel-sampled-alerts',
            generatedAt: '2026-08-31T19:20:00+00:00',
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
                    'latest' => [],
                ],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
                'segments' => [],
            ],
            nodeId: 'node-a',
        );

        $dispatcher->dispatch($report);
        $dispatcher->dispatch($report);

        $firstRecord = $captured[0]['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0] ?? null;
        $secondRecord = $captured[1]['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0] ?? null;

        self::assertIsArray($firstRecord);
        self::assertIsArray($secondRecord);
        self::assertSame('WARN', $firstRecord['severityText'] ?? null);
        self::assertSame('INFO', $secondRecord['severityText'] ?? null);

        $firstAttributes = $this->attributesToMap($firstRecord['attributes'] ?? []);
        $secondAttributes = $this->attributesToMap($secondRecord['attributes'] ?? []);
        self::assertSame('custom', $firstAttributes['db.attributes.alert_sampling.profile'] ?? null);
        self::assertSame('in_memory', $firstAttributes['db.attributes.alert_sampling.store'] ?? null);
        self::assertSame('3', $firstAttributes['db.attributes.alert_sampling.visible_total'] ?? null);
        self::assertArrayNotHasKey('db.alerts.0.name', $secondAttributes);
        self::assertSame('3', $secondAttributes['db.attributes.alert_sampling.suppressed_total'] ?? null);
        self::assertSame('3', $secondAttributes['db.attributes.alert_sampling.cumulative_suppressed_total'] ?? null);
        self::assertStringContainsString('"suppressed_total":3', $secondRecord['body']['stringValue'] ?? '');
    }

    public function test_it_throws_when_collector_returns_error_status(): void
    {
        $dispatcher = new OpenTelemetryDatabaseTelemetryDispatcher(
            endpoint: 'https://collector.internal/v1/logs',
            sender: static fn(string $endpoint, array $payload, array $headers, int $timeoutMs): array => [
                'status' => 500,
                'headers' => [],
                'body' => '{"error":"collector_failed"}',
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected status [500]');

        $dispatcher->dispatch(new DatabaseTelemetryReport(
            requestId: 'req-otel-error',
            tenantId: null,
            traceId: null,
            generatedAt: '2026-08-29T19:05:00+00:00',
            summary: [],
            health: [],
            nodeId: 'node-a',
        ));
    }

    /**
     * @param list<array{key:string, value:array<string, mixed>}> $attributes
     * @return array<string, string|bool|float|int|null>
     */
    private function attributesToMap(array $attributes): array
    {
        $map = [];

        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $key = (string) ($attribute['key'] ?? '');
            $value = is_array($attribute['value'] ?? null) ? $attribute['value'] : [];
            if ($key === '' || $value === []) {
                continue;
            }

            $map[$key] = $value['stringValue']
                ?? $value['intValue']
                ?? $value['doubleValue']
                ?? $value['boolValue']
                ?? null;
        }

        return $map;
    }
}
