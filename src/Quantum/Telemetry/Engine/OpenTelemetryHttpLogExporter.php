<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Engine;

use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\TelemetrySignal;

final class OpenTelemetryHttpLogExporter implements TelemetryExporterInterface
{
    /**
     * @param array<string, string> $headers
     * @param null|\Closure(string, array<string, mixed>, array<string, string>, int): array{status:int, headers:array<string, string>, body:string} $sender
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $serviceName = 'voltstack',
        private readonly string $serviceNamespace = 'voltstack.framework',
        private readonly string $scopeName = 'voltstack',
        private readonly string $scopeVersion = '1.0.0',
        private readonly array $headers = [],
        private readonly int $requestTimeoutMs = 2000,
        private readonly ?\Closure $sender = null,
    ) {}

    public function export(TelemetrySignal $signal): void
    {
        $payload = $this->buildPayload($signal);
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $this->headers);

        $response = $this->send($this->endpoint, $payload, $headers);
        if (($response['status'] ?? 0) >= 400 || ($response['status'] ?? 0) === 0) {
            throw new \RuntimeException(sprintf(
                'OpenTelemetry exporter returned unexpected status [%d].',
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
    private function buildPayload(TelemetrySignal $signal): array
    {
        return [
            'resourceLogs' => [
                [
                    'resource' => [
                        'attributes' => $this->buildResourceAttributes($signal),
                    ],
                    'scopeLogs' => [
                        [
                            'scope' => [
                                'name' => $this->scopeName,
                                'version' => $this->scopeVersion,
                            ],
                            'logRecords' => [
                                [
                                    'timeUnixNano' => $this->toUnixNano($signal->occurredAt),
                                    'severityText' => $this->severityText($signal),
                                    'body' => [
                                        'stringValue' => $this->body($signal),
                                    ],
                                    'attributes' => $this->buildLogAttributes($signal),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{key:string, value:array<string, mixed>}>
     */
    private function buildResourceAttributes(TelemetrySignal $signal): array
    {
        $attributes = [
            $this->attribute('service.name', $this->serviceName),
            $this->attribute('service.namespace', $this->serviceNamespace),
            $this->attribute('telemetry.sdk.name', 'voltstack'),
            $this->attribute('telemetry.sdk.language', 'php'),
            $this->attribute('voltstack.telemetry.source', $signal->source),
        ];

        if ($signal->nodeId !== null && $signal->nodeId !== '') {
            $attributes[] = $this->attribute('service.instance.id', $signal->nodeId);
        }

        return $attributes;
    }

    /**
     * @return list<array{key:string, value:array<string, mixed>}>
     */
    private function buildLogAttributes(TelemetrySignal $signal): array
    {
        $attributes = [
            $this->attribute('voltstack.telemetry.name', $signal->name),
            $this->attribute('voltstack.telemetry.type', $signal->type),
            $this->attribute('voltstack.telemetry.source', $signal->source),
        ];

        if ($signal->requestId !== null && $signal->requestId !== '') {
            $attributes[] = $this->attribute('db.request_id', $signal->requestId);
        }

        if ($signal->tenantId !== null && $signal->tenantId !== '') {
            $attributes[] = $this->attribute('db.tenant_id', $signal->tenantId);
        }

        if ($signal->traceId !== null && $signal->traceId !== '') {
            $attributes[] = $this->attribute('db.trace_id', $signal->traceId);
        }

        if ($signal->nodeId !== null && $signal->nodeId !== '') {
            $attributes[] = $this->attribute('db.node_id', $signal->nodeId);
        }

        $flattened = [];
        $this->flatten($flattened, 'db', $signal->payload);
        $this->flatten($flattened, 'db.attributes', $signal->attributes);
        $this->flatten($flattened, 'db.alerts', $signal->alerts);

        foreach ($flattened as $key => $value) {
            if (count($attributes) >= 256) {
                break;
            }

            $attributes[] = $this->attribute($key, $value);
        }

        return $attributes;
    }

    private function flatten(array &$flattened, string $prefix, mixed $value, int $depth = 0): void
    {
        if ($prefix === '' || $depth >= 4) {
            if ($prefix !== '') {
                $flattened[$prefix] = $this->normalizeValue($value);
            }

            return;
        }

        if (is_array($value)) {
            if ($value === []) {
                $flattened[$prefix] = '[]';

                return;
            }

            foreach ($value as $key => $item) {
                $normalizedKey = is_int($key) ? (string) $key : trim((string) $key);
                if ($normalizedKey === '') {
                    continue;
                }

                $this->flatten($flattened, $prefix . '.' . $normalizedKey, $item, $depth + 1);
            }

            return;
        }

        $flattened[$prefix] = $this->normalizeValue($value);
    }

    private function normalizeValue(mixed $value): string|int|float|bool
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        return $json === false ? '[unserializable]' : $json;
    }

    /**
     * @return array{key:string, value:array<string, mixed>}
     */
    private function attribute(string $key, string|int|float|bool $value): array
    {
        return [
            'key' => $key,
            'value' => match (true) {
                is_bool($value) => ['boolValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                default => ['stringValue' => $value],
            },
        ];
    }

    private function body(TelemetrySignal $signal): string
    {
        $body = json_encode($signal->toArray(), JSON_UNESCAPED_SLASHES);

        return $body === false ? $signal->name : $body;
    }

    private function severityText(TelemetrySignal $signal): string
    {
        $highest = 'INFO';

        foreach ($signal->alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $severity = strtolower(trim((string) ($alert['severity'] ?? '')));
            if ($severity === 'critical' || $severity === 'high') {
                return 'ERROR';
            }

            if ($severity === 'warning') {
                $highest = 'WARN';
            }
        }

        return $highest;
    }

    private function toUnixNano(string $occurredAt): string
    {
        try {
            $date = new \DateTimeImmutable($occurredAt);
        } catch (\Throwable) {
            $date = new \DateTimeImmutable();
        }

        $seconds = $date->format('U');
        $micros = substr($date->format('u') . '000', 0, 6);

        return $seconds . str_pad($micros, 9, '0', STR_PAD_RIGHT);
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
            throw new \RuntimeException('Unable to send OpenTelemetry payload.');
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