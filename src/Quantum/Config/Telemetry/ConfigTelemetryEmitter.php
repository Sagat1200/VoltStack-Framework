<?php

declare(strict_types=1);

namespace Quantum\Config\Telemetry;

use Quantum\Config\Diagnostics\ConfigStatusReport;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;

final class ConfigTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emitStatus(ConfigStatusReport $report): void
    {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'config_status',
            type: 'event',
            source: 'config',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'environment' => $report->environment(),
                'healthy' => $report->healthy(),
                'scope_kind' => $report->scopeKind(),
                'has_active_generation' => $report->hasActiveGeneration(),
                'published_matches_effective' => $report->publishedMatchesEffective(),
            ],
            alerts: array_map(
                static fn (string $message): array => ['message' => $message],
                $report->alerts(),
            ),
        ));
    }
}
