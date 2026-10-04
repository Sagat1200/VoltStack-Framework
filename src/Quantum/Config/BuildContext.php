<?php

declare(strict_types=1);

namespace Quantum\Config;

/**
 * Minimal build context for the incremental configuration pipeline.
 *
 * @phpstan-type ConfigBuildLimits array{
 *     max_documents?: int,
 *     max_depth?: int,
 *     max_errors?: int
 * }
 */
final readonly class BuildContext
{
    /**
     * @param list<SourceDescriptor> $sources
     * @param ConfigBuildLimits $limits
     */
    public function __construct(
        private string $environment,
        private ?string $releaseId = null,
        private ?string $schemaVersion = null,
        private ?string $deploymentConfigRevision = null,
        private array $sources = [],
        private array $limits = [],
    ) {
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function releaseId(): ?string
    {
        return $this->releaseId;
    }

    public function schemaVersion(): ?string
    {
        return $this->schemaVersion;
    }

    public function deploymentConfigRevision(): ?string
    {
        return $this->deploymentConfigRevision;
    }

    /**
     * @return list<SourceDescriptor>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @return ConfigBuildLimits
     */
    public function limits(): array
    {
        return $this->limits;
    }
}
