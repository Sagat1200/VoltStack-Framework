<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Support;

use Quantum\Config\Diagnostics\ConfigRedactor;

trait SanitizesTelemetryPayload
{
    private function sanitizeTelemetryValue(mixed $value, int $depth = 0): mixed
    {
        return (new ConfigRedactor())->redact($value);
    }
}
