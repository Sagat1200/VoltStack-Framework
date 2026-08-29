<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Telemetry\Engine\HttpTelemetryExporter;
use Quantum\Telemetry\TelemetrySignal;

final class HttpTelemetryExporterTest extends TestCase
{
    public function test_it_posts_canonical_signal_payload_to_webhook(): void
    {
        $captured = [];
        $exporter = new HttpTelemetryExporter(
            endpoint: 'https://monitoring.internal/voltstack/telemetry',
            headers: [
                'Authorization' => 'Bearer token',
            ],
            requestTimeoutMs: 2500,
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

        $exporter->export(new TelemetrySignal(
            name: 'database_telemetry',
            type: 'report',
            source: 'database',
            occurredAt: '2026-08-29T10:00:00+00:00',
            payload: [
                'request_id' => 'req-1',
                'summary' => ['total_operations' => 2],
            ],
            attributes: [
                'summary' => ['completed' => 2],
            ],
            alerts: [
                ['name' => 'database.remote_replay_challenge.incompatible', 'severity' => 'critical'],
            ],
            requestId: 'req-1',
            tenantId: 'tenant-a',
            traceId: 'trace-1',
            nodeId: 'node-a',
        ));

        self::assertSame('https://monitoring.internal/voltstack/telemetry', $captured['endpoint']);
        self::assertSame(2500, $captured['timeout_ms']);
        self::assertSame('Bearer token', $captured['headers']['Authorization']);
        self::assertSame('database_telemetry', $captured['headers']['X-VoltStack-Event-Type']);
        self::assertSame('report', $captured['payload']['signal_type']);
        self::assertSame('database', $captured['payload']['source']);
        self::assertSame('req-1', $captured['payload']['request_id']);
        self::assertSame('tenant-a', $captured['payload']['tenant_id']);
        self::assertSame('node-a', $captured['payload']['node_id']);
        self::assertSame(2, $captured['payload']['payload']['summary']['total_operations']);
        self::assertSame('database.remote_replay_challenge.incompatible', $captured['payload']['alerts'][0]['name']);
    }
}
