<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Engine\HttpDatabaseTelemetryDispatcher;

final class HttpDatabaseTelemetryDispatcherTest extends TestCase
{
    public function test_it_posts_database_telemetry_to_webhook_with_remote_replay_alerts(): void
    {
        $captured = [];
        $dispatcher = new HttpDatabaseTelemetryDispatcher(
            endpoint: 'https://monitoring.internal/voltstack/database',
            headers: [
                'Authorization' => 'Bearer secret-token',
            ],
            requestTimeoutMs: 3500,
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
                    'body' => '{"accepted":true}',
                ];
            },
        );

        $dispatcher->dispatch(new DatabaseTelemetryReport(
            requestId: 'req-remote-1',
            tenantId: 'tenant-a',
            traceId: 'trace-remote-1',
            generatedAt: '2026-08-28T10:00:00+00:00',
            summary: [
                'total_operations' => 3,
                'completed' => 3,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
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

        self::assertSame('https://monitoring.internal/voltstack/database', $captured['endpoint']);
        self::assertSame(3500, $captured['timeout_ms']);
        self::assertSame('Bearer secret-token', $captured['headers']['Authorization']);
        self::assertSame('database_telemetry', $captured['payload']['type']);
        self::assertSame('req-remote-1', $captured['payload']['payload']['request_id']);
        self::assertCount(3, $captured['payload']['alerts']);
        self::assertSame('database.remote_replay_challenge.incompatible', $captured['payload']['alerts'][0]['name']);
        self::assertSame('critical', $captured['payload']['alerts'][0]['severity']);
        self::assertSame(1, $captured['payload']['alerts'][0]['count']);
        self::assertSame(0, $captured['payload']['payload']['summary']['alert_sampling']['suppressed_total'] ?? null);
        self::assertSame(1, $captured['payload']['payload']['summary']['remote_replay_challenge']['rejected']);
    }

    public function test_it_throws_when_webhook_returns_error_status(): void
    {
        $dispatcher = new HttpDatabaseTelemetryDispatcher(
            endpoint: 'https://monitoring.internal/voltstack/database',
            sender: static fn(string $endpoint, array $payload, array $headers, int $timeoutMs): array => [
                'status' => 500,
                'headers' => [],
                'body' => '{"accepted":false}',
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected status [500]');

        $dispatcher->dispatch(new DatabaseTelemetryReport(
            requestId: 'req-error',
            tenantId: null,
            traceId: null,
            generatedAt: '2026-08-28T10:05:00+00:00',
            summary: [],
            health: [],
            nodeId: 'node-a',
        ));
    }
}
