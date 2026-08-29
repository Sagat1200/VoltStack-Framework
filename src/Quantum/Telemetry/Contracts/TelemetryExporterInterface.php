<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Contracts;

use Quantum\Telemetry\TelemetrySignal;

interface TelemetryExporterInterface
{
    public function export(TelemetrySignal $signal): void;
}