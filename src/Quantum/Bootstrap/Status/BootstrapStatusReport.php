<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Status;

final readonly class BootstrapStatusReport
{
    /**
     * @param list<string> $alerts
     */
    public function __construct(
        private string $environment,
        private bool $booted,
        private int $providerCount,
        private string $artifactDirectory,
        private bool $hasActiveGeneration,
        private ?string $generationId,
        private ?string $manifestPath,
        private ?string $fingerprint,
        private ?int $schemaVersion,
        private array $alerts = [],
    ) {
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    public function providerCount(): int
    {
        return $this->providerCount;
    }

    public function artifactDirectory(): string
    {
        return $this->artifactDirectory;
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

    public function fingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function schemaVersion(): ?int
    {
        return $this->schemaVersion;
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
            'booted' => $this->booted,
            'provider_count' => $this->providerCount,
            'artifact_directory' => $this->artifactDirectory,
            'has_active_generation' => $this->hasActiveGeneration,
            'generation_id' => $this->generationId,
            'manifest_path' => $this->manifestPath,
            'fingerprint' => $this->fingerprint,
            'schema_version' => $this->schemaVersion,
            'alerts' => $this->alerts,
        ];
    }
}
