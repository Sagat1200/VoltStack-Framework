<?php

declare(strict_types=1);

namespace Quantum\Config\Diagnostics;

final readonly class ConfigStatusReport
{
    /**
     * @param list<array<string, mixed>> $documents
     * @param array<string, mixed> $provenance
     * @param array<string, mixed> $redactedConfig
     * @param array<string, mixed> $redactedOverrides
     * @param list<array<string, mixed>> $scopeLineage
     * @param array<string, mixed>|null $tenantContext
     * @param list<string> $alerts
     */
    public function __construct(
        private string $environment,
        private string $scopeName,
        private string $scopeKind,
        private int $scopeDepth,
        private ?string $scopeId,
        private ?string $parentScopeId,
        private bool $hasScopeOverrides,
        private string $effectiveConfigId,
        private int $documentCount,
        private int $provenanceCount,
        private array $documents,
        private array $provenance,
        private array $redactedConfig,
        private array $redactedOverrides,
        private array $scopeLineage,
        private ?array $tenantContext,
        private bool $hasActiveGeneration,
        private ?string $generationId,
        private ?string $manifestPath,
        private ?string $publishedConfigId,
        private bool $publishedMatchesEffective,
        private array $alerts = [],
    ) {
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function scopeName(): string
    {
        return $this->scopeName;
    }

    public function scopeKind(): string
    {
        return $this->scopeKind;
    }

    public function scopeDepth(): int
    {
        return $this->scopeDepth;
    }

    public function scopeId(): ?string
    {
        return $this->scopeId;
    }

    public function parentScopeId(): ?string
    {
        return $this->parentScopeId;
    }

    public function hasScopeOverrides(): bool
    {
        return $this->hasScopeOverrides;
    }

    public function effectiveConfigId(): string
    {
        return $this->effectiveConfigId;
    }

    public function documentCount(): int
    {
        return $this->documentCount;
    }

    public function provenanceCount(): int
    {
        return $this->provenanceCount;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return $this->provenance;
    }

    /**
     * @return array<string, mixed>
     */
    public function redactedConfig(): array
    {
        return $this->redactedConfig;
    }

    /**
     * @return array<string, mixed>
     */
    public function redactedOverrides(): array
    {
        return $this->redactedOverrides;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function scopeLineage(): array
    {
        return $this->scopeLineage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tenantContext(): ?array
    {
        return $this->tenantContext;
    }

    public function hasActiveGeneration(): bool
    {
        return $this->hasActiveGeneration;
    }

    public function generationId(): ?string
    {
        return $this->generationId;
    }

    public function manifestPath(): ?string
    {
        return $this->manifestPath;
    }

    public function publishedConfigId(): ?string
    {
        return $this->publishedConfigId;
    }

    public function publishedMatchesEffective(): bool
    {
        return $this->publishedMatchesEffective;
    }

    /**
     * @return list<string>
     */
    public function alerts(): array
    {
        return $this->alerts;
    }

    public function healthy(): bool
    {
        return $this->alerts === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'healthy' => $this->healthy(),
            'scope_name' => $this->scopeName,
            'scope_kind' => $this->scopeKind,
            'scope_depth' => $this->scopeDepth,
            'scope_id' => $this->scopeId,
            'parent_scope_id' => $this->parentScopeId,
            'has_scope_overrides' => $this->hasScopeOverrides,
            'effective_config_id' => $this->effectiveConfigId,
            'document_count' => $this->documentCount,
            'provenance_count' => $this->provenanceCount,
            'documents' => $this->documents,
            'provenance' => $this->provenance,
            'redacted_config' => $this->redactedConfig,
            'redacted_overrides' => $this->redactedOverrides,
            'scope_lineage' => $this->scopeLineage,
            'tenant_context' => $this->tenantContext,
            'has_active_generation' => $this->hasActiveGeneration,
            'generation_id' => $this->generationId,
            'manifest_path' => $this->manifestPath,
            'published_config_id' => $this->publishedConfigId,
            'published_matches_effective' => $this->publishedMatchesEffective,
            'alerts' => $this->alerts,
        ];
    }
}
