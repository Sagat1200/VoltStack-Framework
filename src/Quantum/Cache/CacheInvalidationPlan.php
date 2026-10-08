<?php

declare(strict_types=1);

namespace Quantum\Cache;

use JsonSerializable;

final readonly class CacheInvalidationPlan implements JsonSerializable
{
    /**
     * @param array<int, string> $effects
     * @param array<int, string> $warnings
     * @param array<string, mixed> $target
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public string $schemaVersion,
        public string $operationId,
        public string $pool,
        public string $action,
        public bool $dryRun,
        public bool $applySupported,
        public string $scopeFingerprint,
        public string $outcome,
        public array $effects,
        public array $warnings,
        public array $target,
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
            'action' => $this->action,
            'dry_run' => $this->dryRun,
            'apply_supported' => $this->applySupported,
            'scope_fingerprint' => $this->scopeFingerprint,
            'outcome' => $this->outcome,
            'effects' => $this->effects,
            'warnings' => $this->warnings,
            'target' => $this->target,
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
