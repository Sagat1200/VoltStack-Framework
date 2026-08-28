<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class HttpDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    /**
     * @param array<string, string> $headers
     * @param null|\Closure(string, array<string, mixed>, array<string, string>, int): array{status:int, headers:array<string, string>, body:string} $sender
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers = [],
        private readonly int $requestTimeoutMs = 2000,
        private readonly ?\Closure $sender = null,
    ) {}

    public function dispatch(DatabaseTelemetryReport $report): void
    {
        $payload = $this->buildPayload($report);
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-VoltStack-Event-Type' => 'database_telemetry',
        ], $this->headers);

        $response = $this->send($this->endpoint, $payload, $headers);
        if (($response['status'] ?? 0) >= 400 || ($response['status'] ?? 0) === 0) {
            throw new \RuntimeException(sprintf(
                'Database telemetry webhook returned unexpected status [%d].',
                (int) ($response['status'] ?? 0),
            ));
        }
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(DatabaseTelemetryReport $report): array
    {
        return [
            'type' => 'database_telemetry',
            'generated_at' => $report->generatedAt,
            'node_id' => $report->nodeId,
            'request_id' => $report->requestId,
            'tenant_id' => $report->tenantId,
            'trace_id' => $report->traceId,
            'alerts' => $this->buildAlerts($report),
            'payload' => $report->toArray(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildAlerts(DatabaseTelemetryReport $report): array
    {
        $summary = is_array($report->summary['remote_replay_challenge'] ?? null)
            ? $report->summary['remote_replay_challenge']
            : [];

        $alerts = [];
        $incompatible = (int) ($summary['incompatible'] ?? 0);
        if ($incompatible > 0) {
            $alerts[] = [
                'name' => 'database.remote_replay_challenge.incompatible',
                'severity' => 'critical',
                'count' => $incompatible,
                'context' => [
                    'protocols' => is_array($summary['protocols'] ?? null) ? $summary['protocols'] : [],
                    'request_key_ids' => is_array($summary['request_key_ids'] ?? null) ? $summary['request_key_ids'] : [],
                    'response_key_ids' => is_array($summary['response_key_ids'] ?? null) ? $summary['response_key_ids'] : [],
                ],
            ];
        }

        $rejected = (int) ($summary['rejected'] ?? 0);
        if ($rejected > 0) {
            $alerts[] = [
                'name' => 'database.remote_replay_challenge.rejected',
                'severity' => 'high',
                'count' => $rejected,
                'context' => [
                    'protocols' => is_array($summary['protocols'] ?? null) ? $summary['protocols'] : [],
                ],
            ];
        }

        $unavailable = (int) ($summary['unavailable'] ?? 0);
        if ($unavailable > 0) {
            $alerts[] = [
                'name' => 'database.remote_replay_challenge.unavailable',
                'severity' => 'warning',
                'count' => $unavailable,
                'context' => [
                    'observed_operations' => (int) ($summary['observed_operations'] ?? 0),
                ],
            ];
        }

        return $alerts;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string, string>, body:string}
     */
    private function send(string $endpoint, array $payload, array $headers): array
    {
        if ($this->sender instanceof \Closure) {
            return ($this->sender)($endpoint, $payload, $headers, $this->requestTimeoutMs);
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => max(1, (int) ceil($this->requestTimeoutMs / 1000)),
                'ignore_errors' => true,
            ],
        ]);

        $rawBody = file_get_contents($endpoint, false, $context);
        if ($rawBody === false) {
            throw new \RuntimeException('Unable to send database telemetry webhook.');
        }

        $status = 0;
        $normalizedHeaders = [];
        foreach ($http_response_header ?? [] as $index => $headerLine) {
            if ($index === 0) {
                if (preg_match('/\s(\d{3})\s/', $headerLine, $matches) === 1) {
                    $status = (int) $matches[1];
                }

                continue;
            }

            $separator = strpos($headerLine, ':');
            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($headerLine, 0, $separator)));
            $value = trim(substr($headerLine, $separator + 1));
            $normalizedHeaders[$name] = $value;
        }

        return [
            'status' => $status,
            'headers' => $normalizedHeaders,
            'body' => $rawBody,
        ];
    }
}