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
    ) {
    }

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
            'summary' => $this->summary,
            'health' => $this->health,
        ];
    }
}
