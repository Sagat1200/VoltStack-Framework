<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Engine;

use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;

final class TelemetryManager implements TelemetryManagerInterface
{
    public function __construct(
        private readonly TelemetryExporterInterface $exporter,
    ) {
    }

    public function emit(TelemetrySignal $signal): void
    {
        $this->exporter->export($signal);
    }
}
