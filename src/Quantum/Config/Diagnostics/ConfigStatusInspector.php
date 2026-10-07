<?php

declare(strict_types=1);

namespace Quantum\Config\Diagnostics;

use Quantum\Config\ConfigDocument;
use Quantum\Config\ConfigRepository;
use Quantum\Config\ConfigSnapshot;
use Quantum\Config\Publication\ConfigManifestStore;
use Quantum\Config\Publication\ConfigSnapshotCodec;
use Quantum\Config\Scope\ConfigurationScope;
use Quantum\Config\Scope\ConfigurationScopeRegistry;
use VoltStack\Framework\Application;

final class ConfigStatusInspector
{
    public function __construct(
        private readonly ?ConfigRedactor $redactor = null,
        private readonly ?ConfigSnapshotCodec $codec = null,
        private readonly ?ConfigManifestStore $manifestStore = null,
        private readonly ?ConfigurationScopeRegistry $scopeRegistry = null,
    ) {
    }

    public function inspect(Application $app): ConfigStatusReport
    {
        $repository = $app->make(ConfigRepository::class);
        $scope = $this->scopeRegistry()?->findCurrent();
        $effectiveSnapshot = $scope?->snapshot() ?? $repository->snapshot();
        $effectiveConfigId = $effectiveSnapshot->configId() ?? $this->codec()->configId($effectiveSnapshot);

        $store = $this->manifestStore();
        $artifact = $store?->currentArtifact();
        $publishedSnapshot = $store?->currentSnapshot();
        $publishedConfigId = $artifact?->configId()
            ?? $publishedSnapshot?->configId();
        $publishedMatchesEffective = $publishedConfigId !== null
            && $publishedConfigId !== ''
            && $publishedConfigId === $effectiveConfigId;

        $alerts = [];

        if ($artifact === null) {
            $alerts[] = 'No hay una generacion de configuracion activa.';
        } elseif ($publishedSnapshot === null) {
            $alerts[] = 'La generacion activa de configuracion no pudo rehidratarse.';
        } elseif (! $publishedMatchesEffective) {
            $alerts[] = 'El snapshot efectivo difiere de la generacion de configuracion activa.';
        }

        return new ConfigStatusReport(
            environment: $app->environment(),
            scopeKind: $scope?->kind() ?? ($app->hasActiveScope() ? $app->currentScopeKind() : 'root'),
            scopeId: $scope?->id() ?? ($app->hasActiveScope() ? $app->currentScopeId() : null),
            parentScopeId: $scope?->parentId() ?? ($app->hasActiveScope() ? $app->currentScopeParentId() : null),
            hasScopeOverrides: $scope !== null && $scope->overrides() !== [],
            effectiveConfigId: $effectiveConfigId,
            documentCount: count($repository->documents()),
            provenanceCount: count($effectiveSnapshot->provenance()),
            documents: array_map($this->documentToArray(...), $repository->documents()),
            provenance: $effectiveSnapshot->provenance(),
            redactedConfig: $this->redactSnapshot($effectiveSnapshot),
            redactedOverrides: $scope !== null ? $this->redactOverrides($scope) : [],
            hasActiveGeneration: $artifact !== null,
            generationId: $artifact?->generationId(),
            manifestPath: $artifact?->manifestPath(),
            publishedConfigId: $publishedConfigId,
            publishedMatchesEffective: $publishedMatchesEffective,
            alerts: $alerts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function redactSnapshot(ConfigSnapshot $snapshot): array
    {
        $redacted = $this->redactor()->redact($snapshot->all());

        return is_array($redacted) ? $redacted : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function redactOverrides(ConfigurationScope $scope): array
    {
        $redacted = $this->redactor()->redact($scope->overrides());

        return is_array($redacted) ? $redacted : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentToArray(ConfigDocument $document): array
    {
        return [
            'descriptor' => $document->descriptor()->toArray(),
            'schema_version' => $document->schemaVersion(),
            'payload_keys' => array_keys($document->payload()),
        ];
    }

    private function redactor(): ConfigRedactor
    {
        return $this->redactor ?? new ConfigRedactor();
    }

    private function codec(): ConfigSnapshotCodec
    {
        return $this->codec ?? new ConfigSnapshotCodec();
    }

    private function manifestStore(): ?ConfigManifestStore
    {
        return $this->manifestStore;
    }

    private function scopeRegistry(): ?ConfigurationScopeRegistry
    {
        return $this->scopeRegistry;
    }
}
