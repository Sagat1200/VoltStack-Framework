<?php

declare(strict_types=1);

namespace Quantum\Database\Platform;

use Quantum\Database\Contracts\PlatformInterface;

final readonly class GenericPlatform implements PlatformInterface
{
    public function __construct(
        private string $idValue,
        private PlatformCapabilities $capabilities = new PlatformCapabilities(),
    ) {
    }

    public function id(): string
    {
        return $this->idValue;
    }

    public function capabilities(): PlatformCapabilities
    {
        return $this->capabilities;
    }
}
