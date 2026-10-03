<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Telemetry;

use Quantum\Bootstrap\Status\BootstrapStatusReport;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;

final class BootstrapTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emitStatus(BootstrapStatusReport $report): void
    {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'bootstrap_status',
            type: 'event',
            source: 'bootstrap',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'environment' => $report->environment(),
                'booted' => $report->booted(),
                'healthy' => $report->healthy(),
                'active_generation' => $report->hasActiveGeneration(),
            ],
            alerts: array_map(
                static fn(string $message): array => ['message' => $message],
                $report->alerts(),
            ),
        ));
    }
}
