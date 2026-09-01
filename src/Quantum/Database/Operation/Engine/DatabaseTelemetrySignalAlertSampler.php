<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryAlertSamplingStoreInterface;
use Quantum\Telemetry\TelemetrySignal;

final class DatabaseTelemetrySignalAlertSampler
{
    private ?DatabaseTelemetryAlertSamplingStoreInterface $resolvedStore = null;

    /**
     * @param array<string, mixed> $sqgAlertSampling
     */
    public function __construct(
        private readonly array $sqgAlertSampling = [],
        private readonly ?DatabaseTelemetryAlertSamplingStoreInterface $store = null,
    ) {}


    public function apply(TelemetrySignal $signal): TelemetrySignal
    {
        if ($signal->alerts === []) {
            return $signal;
        }

        $filteredAlerts = [];
        $suppressedAlerts = [];
        $changed = false;

        foreach ($signal->alerts as $alert) {
            if (!is_array($alert)) {
                $filteredAlerts[] = $alert;

                continue;
            }

            $alertName = trim((string) ($alert['name'] ?? ''));
            if (!str_starts_with($alertName, 'database.sqg_pipeline.')) {
                $filteredAlerts[] = $alert;

                continue;
            }

            $severity = strtolower(trim((string) ($alert['severity'] ?? '')));
            if (in_array($severity, ['high', 'critical'], true)) {
                $filteredAlerts[] = $alert;

                continue;
            }

            $sampleEvery = $this->sampleEvery($alertName);
            if ($sampleEvery <= 1) {
                $filteredAlerts[] = $alert;

                continue;
            }

            $occurrence = $this->samplingStore()->nextOccurrence(
                $signal->nodeId !== null && trim($signal->nodeId) !== '' ? $signal->nodeId : 'unknown-node',
                $alertName,
            );

            if ($occurrence === 1 || $occurrence % $sampleEvery === 0) {
                $context = is_array($alert['context'] ?? null)
                    ? $alert['context']
                    : [];
                $context['sampling_every'] = $sampleEvery;
                $context['sampling_occurrence'] = $occurrence;
                $alert['context'] = $context;
                $filteredAlerts[] = $alert;
                $changed = true;

                continue;
            }

            $suppressedAlerts[$alertName] = (int) ($suppressedAlerts[$alertName] ?? 0) + 1;
            $changed = true;
        }

        if (!$changed) {
            return $signal;
        }

        $attributes = $signal->attributes;
        if ($suppressedAlerts !== []) {
            $attributes['alert_sampling'] = [
                'suppressed_total' => array_sum($suppressedAlerts),
                'suppressed_alerts' => $suppressedAlerts,
            ];
        }

        return new TelemetrySignal(
            name: $signal->name,
            type: $signal->type,
            source: $signal->source,
            occurredAt: $signal->occurredAt,
            payload: $signal->payload,
            attributes: $attributes,
            alerts: $filteredAlerts,
            requestId: $signal->requestId,
            tenantId: $signal->tenantId,
            traceId: $signal->traceId,
            nodeId: $signal->nodeId,
            version: $signal->version,
        );
    }

    public function reset(): void
    {
        $this->samplingStore()->reset();
    }

    private function sampleEvery(string $alertName): int
    {
        $value = $this->sqgAlertSampling[$alertName] ?? null;

        return is_numeric($value) ? max(1, (int) $value) : 1;
    }

    private function samplingStore(): DatabaseTelemetryAlertSamplingStoreInterface
    {
        if ($this->store instanceof DatabaseTelemetryAlertSamplingStoreInterface) {
            return $this->store;
        }

        $this->resolvedStore ??= new InMemoryDatabaseTelemetryAlertSamplingStore();

        return $this->resolvedStore;
    }
}
