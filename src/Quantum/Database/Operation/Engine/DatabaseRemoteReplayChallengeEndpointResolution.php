<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

final readonly class DatabaseRemoteReplayChallengeEndpointResolution
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $status,
        public ?string $nodeId = null,
        public ?string $endpoint = null,
        public ?string $strategy = null,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'node_id' => $this->nodeId,
            'endpoint' => $this->endpoint,
            'strategy' => $this->strategy,
            'details' => $this->details,
        ];
    }
}