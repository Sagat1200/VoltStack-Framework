<?php

declare(strict_types=1);

namespace Quantum\Cache;

use JsonSerializable;

final readonly class RepositoryExplain implements JsonSerializable
{
    /**
     * @param array<int, string> $effects
     * @param array<int, string> $warnings
     * @param array<string, mixed> $lookup
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $policies
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public string $schemaVersion,
        public string $operationId,
        public string $scopeFingerprint,
        public string $outcome,
        public array $effects,
        public array $warnings,
        public string $key,
        public string $normalizedKey,
        public array $lookup,
        public array $plan,
        public array $policies,
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
            'scope_fingerprint' => $this->scopeFingerprint,
            'outcome' => $this->outcome,
            'effects' => $this->effects,
            'warnings' => $this->warnings,
            'key' => $this->key,
            'normalized_key' => $this->normalizedKey,
            'lookup' => $this->lookup,
            'plan' => $this->plan,
            'policies' => $this->policies,
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
