<?php

declare(strict_types=1);

namespace Quantum\Telemetry;

final readonly class TelemetrySignal
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, scalar|array<string, scalar|int|float|bool|null>|null> $attributes
     * @param list<array<string, mixed>> $alerts
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $source,
        public string $occurredAt,
        public array $payload = [],
        public array $attributes = [],
        public array $alerts = [],
        public ?string $requestId = null,
        public ?string $tenantId = null,
        public ?string $traceId = null,
        public ?string $nodeId = null,
        public int $version = 1,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'source' => $this->source,
            'version' => $this->version,
            'occurred_at' => $this->occurredAt,
            'request_id' => $this->requestId,
            'tenant_id' => $this->tenantId,
            'trace_id' => $this->traceId,
            'node_id' => $this->nodeId,
            'attributes' => $this->attributes,
            'alerts' => $this->alerts,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            name: (string) ($payload['name'] ?? ''),
            type: (string) ($payload['type'] ?? 'event'),
            source: (string) ($payload['source'] ?? 'unknown'),
            occurredAt: (string) ($payload['occurred_at'] ?? ''),
            payload: is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
            attributes: is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
            alerts: is_array($payload['alerts'] ?? null) ? array_values($payload['alerts']) : [],
            requestId: isset($payload['request_id']) ? (string) $payload['request_id'] : null,
            tenantId: isset($payload['tenant_id']) ? (string) $payload['tenant_id'] : null,
            traceId: isset($payload['trace_id']) ? (string) $payload['trace_id'] : null,
            nodeId: isset($payload['node_id']) ? (string) $payload['node_id'] : null,
            version: max(1, (int) ($payload['version'] ?? 1)),
        );
    }
}
