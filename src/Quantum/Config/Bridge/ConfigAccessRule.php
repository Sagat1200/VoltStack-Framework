<?php

declare(strict_types=1);

namespace Quantum\Config\Bridge;

final readonly class ConfigAccessRule
{
    public function __construct(
        private string $path,
        private ConfigAccessMode $mode,
        private ?string $consumer = null,
        private ?string $scope = null,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function mode(): ConfigAccessMode
    {
        return $this->mode;
    }

    public function consumer(): ?string
    {
        return $this->consumer;
    }

    public function scope(): ?string
    {
        return $this->scope;
    }
}
