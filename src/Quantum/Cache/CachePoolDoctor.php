<?php

declare(strict_types=1);

namespace Quantum\Cache;

use JsonSerializable;

final readonly class CachePoolDoctor implements JsonSerializable
{
    /**
     * @param array<int, string> $effects
     * @param array<int, string> $warnings
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public string $schemaVersion,
        public string $operationId,
        public string $pool,
        public string $scopeFingerprint,
        public string $outcome,
        public array $effects,
        public array $warnings,
        public array $configuration,
        public array $diagnostics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'operation_id' => $this->operationId,
            'pool' => $this->pool,
            'scope_fingerprint' => $this->scopeFingerprint,
            'outcome' => $this->outcome,
            'effects' => $this->effects,
            'warnings' => $this->warnings,
            'configuration' => $this->configuration,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
