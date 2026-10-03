<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Telemetry;

use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationReport;

final class RuntimeBudgetCalibrationTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emit(RuntimeBudgetCalibrationReport $report): void
    {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'runtime_budget_calibration',
            type: 'event',
            source: 'runtime',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'driver' => $report->driver(),
                'profile' => $report->profile(),
                'passed' => $report->passed(),
                'measured_iterations' => $report->measuredIterations(),
                'failed_iterations' => $report->failedIterations(),
            ],
            alerts: array_map(
                static fn(array $failure): array => [
                    'iteration' => $failure['iteration'],
                    'violations' => $failure['violations'],
                ],
                $report->failures(),
            ),
        ));
    }
}
