<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Manifest;

final readonly class BootstrapBuildArtifact
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $generationId,
        private string $fingerprint,
        private string $manifestPath,
        private array $payload,
        private int $createdAt,
    ) {
    }

    public function generationId(): string
    {
        return $this->generationId;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
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
