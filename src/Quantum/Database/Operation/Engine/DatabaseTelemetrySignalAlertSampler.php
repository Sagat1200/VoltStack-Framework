<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryAlertSamplingStoreInterface;
use Quantum\Telemetry\TelemetrySignal;

final class DatabaseTelemetrySignalAlertSampler
{
    private ?DatabaseTelemetryAlertSamplingStoreInterface $resolvedStore = null;
    private int $cumulativeVisibleTotal = 0;
    private int $cumulativeSuppressedTotal = 0;

    /**
     * @var array<string, int>
     */
    private array $cumulativeVisibleAlerts = [];

    /**
     * @var array<string, int>
     */
    private array $cumulativeSuppressedAlerts = [];

    /**
     * @param array<string, mixed> $sqgAlertSampling
     */
    public function __construct(
        private readonly array $sqgAlertSampling = [],
        private readonly ?DatabaseTelemetryAlertSamplingStoreInterface $store = null,
        private readonly ?string $samplingProfile = null,
    ) {}


    public function apply(TelemetrySignal $signal): TelemetrySignal
    {
        if ($signal->alerts === []) {
            return $signal;
        }

        $filteredAlerts = [];
        $suppressedAlerts = [];
        $visibleAlerts = [];
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
                $visibleAlerts[$alertName] = (int) ($visibleAlerts[$alertName] ?? 0) + 1;
                $this->cumulativeVisibleAlerts[$alertName] = (int) ($this->cumulativeVisibleAlerts[$alertName] ?? 0) + 1;
                $this->cumulativeVisibleTotal++;
                $changed = true;

                continue;
            }

            $suppressedAlerts[$alertName] = (int) ($suppressedAlerts[$alertName] ?? 0) + 1;
            $this->cumulativeSuppressedAlerts[$alertName] = (int) ($this->cumulativeSuppressedAlerts[$alertName] ?? 0) + 1;
            $this->cumulativeSuppressedTotal++;
            $changed = true;
        }

        if (!$changed) {
            return $signal;
        }

        $attributes = $signal->attributes;
        if ($suppressedAlerts !== [] || $visibleAlerts !== []) {
            $metrics = $this->samplingStore()->metrics();
            $attributes['alert_sampling'] = [
                'profile' => $this->normalizedSamplingProfile(),
                'store' => $metrics['store'] ?? null,
                'window_seconds' => $metrics['window_seconds'] ?? null,
                'visible_total' => array_sum($visibleAlerts),
                'visible_alerts' => $visibleAlerts,
                'suppressed_total' => array_sum($suppressedAlerts),
                'suppressed_alerts' => $suppressedAlerts,
                'cumulative_visible_total' => $this->cumulativeVisibleTotal,
                'cumulative_visible_alerts' => $this->cumulativeVisibleAlerts,
                'cumulative_suppressed_total' => $this->cumulativeSuppressedTotal,
                'cumulative_suppressed_alerts' => $this->cumulativeSuppressedAlerts,
                'pruned_records_total' => $metrics['pruned_records_total'] ?? 0,
                'last_pruned_records' => $metrics['last_pruned_records'] ?? 0,
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
        $this->cumulativeVisibleTotal = 0;
        $this->cumulativeSuppressedTotal = 0;
        $this->cumulativeVisibleAlerts = [];
        $this->cumulativeSuppressedAlerts = [];
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

    private function normalizedSamplingProfile(): string
    {
        $profile = strtolower(trim((string) $this->samplingProfile));

        return $profile !== '' ? $profile : 'custom';
    }
}
