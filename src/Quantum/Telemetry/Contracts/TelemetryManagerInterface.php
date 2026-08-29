<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Contracts;

use Quantum\Telemetry\TelemetrySignal;

interface TelemetryManagerInterface
{
    public function emit(TelemetrySignal $signal): void;
}
