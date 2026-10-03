<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Telemetry;

use Quantum\Bootstrap\Phase\BootstrapState;

final readonly class BootstrapPhaseProfile
{
    public function __construct(
        private BootstrapState $phase,
        private float $durationMs,
        private bool $successful = true,
    ) {
    }

    public function phase(): BootstrapState
    {
        return $this->phase;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    public function successful(): bool
    {
        return $this->successful;
    }

    /**
     * @return array{phase: string, duration_ms: float, successful: bool}
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase->value,
            'duration_ms' => $this->durationMs,
            'successful' => $this->successful,
        ];
    }
}
