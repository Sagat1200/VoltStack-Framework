<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseTelemetryReport
{
    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $health
     */
    public function __construct(
        public string $requestId,
        public ?string $tenantId,
        public ?string $traceId,
        public string $generatedAt,
        public array $summary,
        public array $health,
        public ?string $nodeId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'tenant_id' => $this->tenantId,
            'trace_id' => $this->traceId,
            'generated_at' => $this->generatedAt,
            'node_id' => $this->nodeId,
            'summary' => $this->summary,
            'health' => $this->health,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            requestId: (string) ($payload['request_id'] ?? ''),
            tenantId: isset($payload['tenant_id']) ? (string) $payload['tenant_id'] : null,
            traceId: isset($payload['trace_id']) ? (string) $payload['trace_id'] : null,
            generatedAt: (string) ($payload['generated_at'] ?? ''),
            summary: is_array($payload['summary'] ?? null) ? $payload['summary'] : [],
            health: is_array($payload['health'] ?? null) ? $payload['health'] : [],
            nodeId: isset($payload['node_id']) ? (string) $payload['node_id'] : null,
        );
    }
}
