<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\DatabaseTelemetryReport;
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
        self::assertSame('1', $attributes['db.attributes.sqg_pipeline.optimizer_strategies.safe_rule_bundle_v1'] ?? null);
        self::assertSame('database.remote_replay_challenge.incompatible', $attributes['db.alerts.0.name'] ?? null);
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
