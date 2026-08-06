<?php

declare(strict_types=1);

namespace Quantum\Compilation;

final readonly class Build
{
    public function __construct(
        public string $id,
        public int $createdAt,
        public int $controllerCount = 0,
        public string $format = 'php',
        public bool $active = false,
        public int $activatedAt = 0,
        public string $previousBuildId = '',
    ) {}

    public function withActivated(): self
    {
        return new self(
            id: $this->id,
            createdAt: $this->createdAt,
            controllerCount: $this->controllerCount,
            format: $this->format,
            active: true,
            activatedAt: time(),
            previousBuildId: $this->previousBuildId,
        );
    }

    public function withControllerCount(int $count): self
    {
        return new self(
            id: $this->id,
            createdAt: $this->createdAt,
            controllerCount: $count,
            format: $this->format,
            active: $this->active,
            activatedAt: $this->activatedAt,
            previousBuildId: $this->previousBuildId,
        );
    }
}