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
        public string $contextFingerprint,
        public array $capabilities,
        public array $versions,
        public string $storageNamespace,
        public array $invalidationScopes,
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
            'context_fingerprint' => $this->contextFingerprint,
            'capabilities' => $this->capabilities,
            'versions' => $this->versions,
            'storage_namespace' => $this->storageNamespace,
            'invalidation_scopes' => $this->invalidationScopes,
            'store' => $this->store,
            'observed_at_ms' => $this->observedAtMs,
            'clear_strategy' => $this->clearStrategy,
        ];
    }
}
