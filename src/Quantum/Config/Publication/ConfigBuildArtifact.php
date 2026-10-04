<?php

declare(strict_types=1);

namespace Quantum\Config\Publication;

final readonly class ConfigBuildArtifact
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $generationId,
        private string $configId,
        private string $manifestPath,
        private array $payload,
        private int $createdAt,
    ) {
    }

    public function generationId(): string
    {
        return $this->generationId;
    }

    public function configId(): string
    {
        return $this->configId;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }
}
