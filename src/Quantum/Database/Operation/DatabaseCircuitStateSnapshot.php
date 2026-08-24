<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseCircuitStateSnapshot
{
    public function __construct(
        public string $segment,
        public string $connectionName,
        public string $driver,
        public string $operationKind,
        public string $logicalTarget,
        public string $state,
        public int $failureCount,
        public ?string $openedAt,
    ) {}

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'segment' => $this->segment,
            'connection_name' => $this->connectionName,
            'driver' => $this->driver,
            'operation_kind' => $this->operationKind,
            'logical_target' => $this->logicalTarget,
            'state' => $this->state,
            'failure_count' => $this->failureCount,
            'opened_at' => $this->openedAt,
        ];
    }
}