<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\DatabaseTelemetryStore;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\TelemetrySignal;

final readonly class DatabaseTelemetryDispatchPreparation
{
    public function __construct(
        private DatabaseTelemetrySignalMapper $mapper,
        private DatabaseTelemetrySignalAlertSampler $alertSampler,
    ) {}

    /**
     * @return array{report:DatabaseTelemetryReport,signal:TelemetrySignal}
     */
    public function prepare(DatabaseTelemetryReport $report): array
    {
        $signal = $this->alertSampler->apply($this->mapper->map($report));
        $summary = $report->summary;
        $summary['alert_sampling'] = array_merge(
            DatabaseTelemetryStore::emptyAlertSamplingSummary(),
            is_array($summary['alert_sampling'] ?? null) ? $summary['alert_sampling'] : [],
            is_array($signal->attributes['alert_sampling'] ?? null) ? $signal->attributes['alert_sampling'] : [],
        );
        $enrichedReport = $report->withSummary($summary);

        return [
            'report' => $enrichedReport,
            'signal' => $this->withPayload($signal, $enrichedReport),
        ];
    }

    private function withPayload(TelemetrySignal $signal, DatabaseTelemetryReport $report): TelemetrySignal
    {
        return new TelemetrySignal(
            name: $signal->name,
            type: $signal->type,
            source: $signal->source,
            occurredAt: $signal->occurredAt,
            payload: $report->toArray(),
            attributes: $signal->attributes,
            alerts: $signal->alerts,
            requestId: $signal->requestId,
            tenantId: $signal->tenantId,
            traceId: $signal->traceId,
            nodeId: $signal->nodeId,
            version: $signal->version,
        );
    }
}