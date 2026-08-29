<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Engine;

use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\TelemetrySignal;

final class InMemoryTelemetryExporter implements TelemetryExporterInterface
{
    /**
     * @var list<TelemetrySignal>
     */
    private array $signals = [];

    public function __construct(
        private readonly int $maxSignals = 256,
    ) {
    }

    public function export(TelemetrySignal $signal): void
    {
        $this->signals[] = $signal;

        if (count($this->signals) <= $this->maxSignals) {
            return;
        }

        $excess = count($this->signals) - $this->maxSignals;
        if ($excess > 0) {
            $this->signals = array_slice($this->signals, $excess);
        }
    }

    /**
     * @return list<TelemetrySignal>
     */
    public function signals(): array
    {
        return $this->signals;
    }

    public function last(): ?TelemetrySignal
    {
        $last = $this->signals[array_key_last($this->signals)] ?? null;

        return $last instanceof TelemetrySignal ? $last : null;
    }

    public function clear(): void
    {
        $this->signals = [];
    }
}
