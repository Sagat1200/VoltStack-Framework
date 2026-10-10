<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Telemetry;

use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;
use VoltStack\Runtime\Evidence\RuntimeCapabilityCheckCatalog;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationReport;

final class RuntimeCapabilityEvidenceTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emitIngest(RuntimeCapabilityVerificationReport $report, bool $published): void
    {
        $audit = RuntimeCapabilityCheckCatalog::auditReport($report);
        $metadata = $report->metadata();
        $source = (is_string($metadata['source'] ?? null) && trim($metadata['source']) !== '')
            ? trim($metadata['source'])
            : 'unknown';

        $this->telemetry->emit(new TelemetrySignal(
            name: 'runtime_capability_evidence_ingest',
            type: 'event',
            source: 'runtime',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'driver' => $report->driver(),
                'platform' => $report->platform(),
                'profile' => $report->profile(),
                'valid' => $report->valid(),
                'published' => $published,
                'proves_native_integration' => $report->provesNativeIntegration(),
                'evidence_level' => $report->evidenceLevel(),
                'total_checks' => $report->totalChecks(),
                'passed_checks' => $report->passedChecks(),
                'failed_checks' => $report->failedChecks(),
                'source' => $source,
                'unknown_check_ids' => $audit['unknown_ids'],
                'missing_required_check_ids' => $audit['missing_required_ids'],
                'failed_required_check_ids' => $audit['failed_required_ids'],
            ],
            alerts: array_map(
                static fn($check): array => $check->toArray(),
                array_filter(
                    $report->checks(),
                    static fn($check): bool => ! $check->passed(),
                ),
            ),
        ));
    }
}
