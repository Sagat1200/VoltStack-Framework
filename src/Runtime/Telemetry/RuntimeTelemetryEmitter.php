<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Telemetry;

use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;
use VoltStack\Runtime\Status\RuntimeStatusReport;

final class RuntimeTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emitStatus(RuntimeStatusReport $report): void
    {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'runtime_status',
            type: 'event',
            source: 'runtime',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'driver' => $report->driver(),
                'healthy' => $report->healthy(),
                'persistent' => $report->persistent(),
                'concurrent' => $report->concurrent(),
                'budget_source' => $report->recommendedBudget()->source(),
                'has_active_calibration' => $report->activeCalibration() !== null,
                'capability_evidence_level' => $report->capabilityEvidenceLevel(),
                'native_integration_verified' => $report->nativeIntegrationVerified(),
                'rollout_ready' => $report->rolloutReadiness()->ready(),
                'rollout_strategy' => $report->rolloutReadiness()->strategy(),
            ],
            alerts: array_map(
                static fn(string $message): array => ['message' => $message],
                [...$report->alerts(), ...$report->rolloutReadiness()->gaps()],
            ),
        ));
    }
}
