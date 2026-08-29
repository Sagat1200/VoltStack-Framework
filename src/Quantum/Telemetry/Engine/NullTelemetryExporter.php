<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Engine;

use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\TelemetrySignal;

final class NullTelemetryExporter implements TelemetryExporterInterface
{
    public function export(TelemetrySignal $signal): void
    {
    }
}
