<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

final readonly class RuntimeCapabilities
{
    public function __construct(
        private bool $persistent,
        private bool $concurrent,
        private bool $streaming,
        private bool $drainControl,
        private bool $nativeHttp,
    ) {
    }

    public function persistent(): bool
    {
        return $this->persistent;
    }

    public function concurrent(): bool
    {
        return $this->concurrent;
    }

    public function streaming(): bool
    {
        return $this->streaming;
    }

    public function drainControl(): bool
    {
        return $this->drainControl;
    }

    public function nativeHttp(): bool
    {
        return $this->nativeHttp;
    }
}
