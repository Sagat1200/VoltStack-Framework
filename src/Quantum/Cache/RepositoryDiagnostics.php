<?php

declare(strict_types=1);

namespace Quantum\Cache;

final readonly class RepositoryDiagnostics
{
    /**
     * @param array<string, bool> $capabilities
     * @param array<string, string> $versions
     * @param array<string, mixed> $store
     */
    public function __construct(
        public string $sourceLevel,
        public CacheContext $context,
        public array $capabilities,
        public array $versions,
        public array $store,
        public int $observedAtMs,
        public string $clearStrategy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_level' => $this->sourceLevel,
            'context' => $this->context->toArray(),
            'capabilities' => $this->capabilities,
            'versions' => $this->versions,
            'store' => $this->store,
            'observed_at_ms' => $this->observedAtMs,
            'clear_strategy' => $this->clearStrategy,
        ];
    }
}
