<?php

declare(strict_types=1);

namespace Quantum\Config\Bridge;

use Quantum\Config\ConfigSnapshot;

final readonly class ConfigBridgeSnapshot
{
    public function __construct(
        private ConfigSnapshot $source,
        private array $staticPaths,
        private array $scopedPaths,
    ) {
    }

    public function source(): ConfigSnapshot
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>
     */
    public function staticPaths(): array
    {
        return $this->staticPaths;
    }

    /**
     * @return array<string, mixed>
     */
    public function scopedPaths(): array
    {
        return $this->scopedPaths;
    }
}
