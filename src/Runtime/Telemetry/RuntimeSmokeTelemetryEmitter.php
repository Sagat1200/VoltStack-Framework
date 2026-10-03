<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Telemetry;

use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;
use VoltStack\Runtime\Smoke\RuntimeSmokeCheckReport;

final class RuntimeSmokeTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emit(RuntimeSmokeCheckReport $report): void
    {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'runtime_smoke',
            type: 'event',
            source: 'runtime',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'driver' => $report->driver(),
                'profile' => $report->profile(),
                'passed' => $report->passed(),
                'request_count' => count($report->requests()),
                'reuse_guard_passed' => $report->reuse()->passed(),
            ],
            alerts: array_map(
                static fn(string $message): array => ['message' => $message],
                $report->violations(),
            ),
        ));
    }
}
