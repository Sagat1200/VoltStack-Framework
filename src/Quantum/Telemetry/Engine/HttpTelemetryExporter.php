<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Engine;

use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\TelemetrySignal;

final class HttpTelemetryExporter implements TelemetryExporterInterface
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

    public function export(TelemetrySignal $signal): void
    {
        $payload = [
            'type' => $signal->name,
            'signal_type' => $signal->type,
            'source' => $signal->source,
            'version' => $signal->version,
            'occurred_at' => $signal->occurredAt,
            'request_id' => $signal->requestId,
            'tenant_id' => $signal->tenantId,
            'trace_id' => $signal->traceId,
            'node_id' => $signal->nodeId,
            'attributes' => $signal->attributes,
            'alerts' => $signal->alerts,
            'payload' => $signal->payload,
        ];
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-VoltStack-Event-Type' => $signal->name,
            'X-VoltStack-Telemetry-Type' => $signal->type,
            'X-VoltStack-Telemetry-Source' => $signal->source,
        ], $this->headers);

        $response = $this->send($this->endpoint, $payload, $headers);
        if (($response['status'] ?? 0) >= 400 || ($response['status'] ?? 0) === 0) {
            throw new \RuntimeException(sprintf(
                'Telemetry exporter returned unexpected status [%d].',
                (int) ($response['status'] ?? 0),
            ));
        }
    }

    public function endpoint(): string
    {
        return $this->endpoint;
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
            throw new \RuntimeException('Unable to send telemetry payload.');
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
