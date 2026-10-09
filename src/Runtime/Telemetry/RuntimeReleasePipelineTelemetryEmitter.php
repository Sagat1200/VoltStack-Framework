<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Telemetry;

use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;
use VoltStack\Runtime\Pipeline\RuntimeReleasePipelineReport;

final class RuntimeReleasePipelineTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emit(RuntimeReleasePipelineReport $report): void
    {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'runtime_release_pipeline',
            type: 'event',
            source: 'runtime',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'driver' => $report->driver(),
                'profile' => $report->profile(),
                'passed' => $report->passed(),
                'failed_stage' => $report->failedStage(),
                'rollback_triggered' => $report->rollback()->triggered(),
                'capability_evidence_level' => $report->capabilityEvidenceLevel(),
                'native_integration_verified' => $report->nativeIntegrationVerified(),
                'drain_required' => $report->drain()->required(),
                'drain_action' => $report->drain()->action(),
            ],
            alerts: array_map(
                static fn(string $message): array => ['message' => $message],
                $report->violations(),
            ),
        ));
    }
}
