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
        $summary['latest'] = $this->enrichLatestEntries(
            is_array($summary['latest'] ?? null) ? $summary['latest'] : [],
            is_array($summary['alert_sampling'] ?? null) ? $summary['alert_sampling'] : [],
        );
        $enrichedReport = $report->withSummary($summary);

        return [
            'report' => $enrichedReport,
            'signal' => $this->withPayload($signal, $enrichedReport),
        ];
    }

    /**
     * @param list<array<string, mixed>> $latest
     * @param array<string, mixed> $alertSampling
     * @return list<array<string, mixed>>
     */
    private function enrichLatestEntries(array $latest, array $alertSampling): array
    {
        $visibleAlerts = is_array($alertSampling['visible_alerts'] ?? null) ? $alertSampling['visible_alerts'] : [];
        $suppressedAlerts = is_array($alertSampling['suppressed_alerts'] ?? null) ? $alertSampling['suppressed_alerts'] : [];

        return array_values(array_map(function (array $entry) use ($visibleAlerts, $suppressedAlerts): array {
            $pipeline = is_array($entry['sqg_pipeline'] ?? null) ? $entry['sqg_pipeline'] : null;
            if ($pipeline === null) {
                return $entry;
            }

            $descriptions = $this->mapper->describeSqgOperationAlerts($pipeline);
            if ($descriptions === []) {
                return $entry;
            }

            $entry['alert_sampling'] = [
                'potential_alerts' => array_values(array_map(
                    static function (array $description) use ($visibleAlerts, $suppressedAlerts): array {
                        $name = is_scalar($description['name'] ?? null) ? trim((string) $description['name']) : '';
                        $state = 'not_promoted';
                        if ($name !== '' && (int) ($suppressedAlerts[$name] ?? 0) > 0) {
                            $state = 'suppressed';
                        } elseif ($name !== '' && (int) ($visibleAlerts[$name] ?? 0) > 0) {
                            $state = 'visible';
                        }

                        return [
                            'name' => $name,
                            'severity' => $description['severity'] ?? null,
                            'state' => $state,
                            'context' => is_array($description['context'] ?? null) ? $description['context'] : [],
                        ];
                    },
                    $descriptions,
                )),
            ];

            return $entry;
        }, $latest));
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
