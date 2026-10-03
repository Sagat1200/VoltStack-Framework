<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Telemetry;

use Quantum\Bootstrap\Release\BootstrapReleaseCheckReport;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;

final class BootstrapBudgetTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emit(BootstrapReleaseCheckReport $report): void
    {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'bootstrap_budget',
            type: 'event',
            source: 'bootstrap',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'passed' => $report->passed(),
                'profile' => $report->profile(),
                'state' => $report->result()->state()->value,
            ],
            alerts: array_map(
                static fn(string $message): array => ['message' => $message],
                $report->budget()->violations(),
            ),
        ));
    }
}
