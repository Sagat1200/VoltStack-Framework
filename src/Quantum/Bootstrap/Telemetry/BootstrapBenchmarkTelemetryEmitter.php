<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Telemetry;

use Quantum\Bootstrap\Benchmark\BootstrapBenchmarkReport;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;

final class BootstrapBenchmarkTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emit(BootstrapBenchmarkReport $report): void
    {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'bootstrap_benchmark',
            type: 'event',
            source: 'bootstrap',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'profile' => $report->profile(),
                'passed' => $report->passed(),
                'warm_faster' => $report->warmFaster(),
            ],
            alerts: array_map(
                static fn(string $message): array => ['message' => $message],
                $report->violations(),
            ),
        ));
    }
}
