<?php

declare(strict_types=1);

namespace Quantum\Database\Runtime;

use Quantum\Database\Config\DatabaseConfiguration;

final class DatabaseExecutionScope
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $id,
        private readonly ?string $runtimeRequestId,
        private readonly float $startedAt,
        private readonly DatabaseConfiguration $configuration,
        private array $metadata = [],
        private ?float $finalizedAt = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function runtimeRequestId(): ?string
    {
        return $this->runtimeRequestId;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function configuration(): DatabaseConfiguration
    {
        return $this->configuration;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function finalize(): void
    {
        if ($this->finalizedAt !== null) {
            return;
        }

        $this->finalizedAt = microtime(true);
    }

    public function isFinalized(): bool
    {
        return $this->finalizedAt !== null;
    }

    public function finalizedAt(): ?float
    {
        return $this->finalizedAt;
    }
}
