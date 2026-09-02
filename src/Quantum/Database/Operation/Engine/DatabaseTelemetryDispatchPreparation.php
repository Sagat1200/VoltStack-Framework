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
        $summary['alert_sampling'] = $this->enrichAlertSamplingAggregates(
            is_array($summary['alert_sampling'] ?? null) ? $summary['alert_sampling'] : [],
            is_array($summary['latest'] ?? null) ? $summary['latest'] : [],
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

    /**
     * @param array<string, mixed> $alertSampling
     * @param list<array<string, mixed>> $latest
     * @return array<string, mixed>
     */
    private function enrichAlertSamplingAggregates(array $alertSampling, array $latest): array
    {
        $byFingerprint = [];
        $byLogicalTarget = [];

        foreach ($latest as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $potentialAlerts = is_array($entry['alert_sampling']['potential_alerts'] ?? null)
                ? $entry['alert_sampling']['potential_alerts']
                : [];

            if ($potentialAlerts === []) {
                continue;
            }

            $fingerprint = is_scalar($entry['fingerprint'] ?? null) ? trim((string) $entry['fingerprint']) : '';
            $logicalTarget = is_scalar($entry['logical_target'] ?? null) ? trim((string) $entry['logical_target']) : '';
            $operationMarker = $fingerprint !== '' ? $fingerprint : spl_object_hash((object) $entry);

            foreach ($potentialAlerts as $potentialAlert) {
                if (!is_array($potentialAlert)) {
                    continue;
                }

                $name = is_scalar($potentialAlert['name'] ?? null) ? trim((string) $potentialAlert['name']) : '';
                $state = is_scalar($potentialAlert['state'] ?? null) ? trim((string) $potentialAlert['state']) : '';

                if ($name === '' || !in_array($state, ['visible', 'suppressed', 'not_promoted'], true)) {
                    continue;
                }

                if ($fingerprint !== '') {
                    $this->ensureGroupedAlertBucket($byFingerprint, $fingerprint, $operationMarker);
                    $this->incrementGroupedAlert($byFingerprint, $fingerprint, $name, $state, $entry);
                }

                if ($logicalTarget !== '') {
                    $this->ensureGroupedAlertBucket($byLogicalTarget, $logicalTarget, $operationMarker);
                    $this->incrementGroupedAlert($byLogicalTarget, $logicalTarget, $name, $state, $entry);
                }
            }
        }

        $alertSampling['by_fingerprint'] = $this->finalizeGroupedAlerts($byFingerprint);
        $alertSampling['by_logical_target'] = $this->finalizeGroupedAlerts($byLogicalTarget);

        return $alertSampling;
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, mixed> $entry
     */
    private function incrementGroupedAlert(array &$groups, string $groupKey, string $alertName, string $state, array $entry): void
    {
        $totalKey = $state . '_total';
        $groups[$groupKey][$totalKey] = (int) ($groups[$groupKey][$totalKey] ?? 0) + 1;

        if (!isset($groups[$groupKey]['alerts'][$alertName])) {
            $groups[$groupKey]['alerts'][$alertName] = [
                'visible' => 0,
                'suppressed' => 0,
                'not_promoted' => 0,
                'logical_target' => $entry['logical_target'] ?? null,
                'fingerprint' => $entry['fingerprint'] ?? null,
            ];
        }

        $groups[$groupKey]['alerts'][$alertName][$state] = (int) ($groups[$groupKey]['alerts'][$alertName][$state] ?? 0) + 1;
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     */
    private function ensureGroupedAlertBucket(array &$groups, string $groupKey, string $operationMarker): void
    {
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'operations' => 0,
                'visible_total' => 0,
                'suppressed_total' => 0,
                'not_promoted_total' => 0,
                'alerts' => [],
                '_operation_markers' => [],
            ];
        }

        if (!in_array($operationMarker, $groups[$groupKey]['_operation_markers'], true)) {
            $groups[$groupKey]['_operation_markers'][] = $operationMarker;
            $groups[$groupKey]['operations'] = (int) $groups[$groupKey]['operations'] + 1;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     * @return array<string, array<string, mixed>>
     */
    private function finalizeGroupedAlerts(array $groups): array
    {
        foreach ($groups as $groupKey => $group) {
            if (!is_array($group)) {
                unset($groups[$groupKey]);
                continue;
            }

            unset($group['_operation_markers']);
            $groups[$groupKey] = $group;
        }

        return $groups;
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