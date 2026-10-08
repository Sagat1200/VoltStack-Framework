<?php

declare(strict_types=1);

namespace Quantum\Config\Telemetry;

use Quantum\Config\Release\ConfigReleaseCheckReport;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;

final class ConfigReleaseTelemetryEmitter
{
    public function __construct(private readonly TelemetryManagerInterface $telemetry)
    {
    }

    public function emit(ConfigReleaseCheckReport $report): void
    {
        $status = $report->status();

        $this->telemetry->emit(new TelemetrySignal(
            name: 'config_release_check',
            type: 'config_release_check',
            source: 'config',
            occurredAt: date('c'),
            payload: $report->toArray(),
            attributes: [
                'passed' => $report->passed(),
                'environment' => $status->environment(),
                'scope_kind' => $status->scopeKind(),
                'require_active_generation' => $report->requireActiveGeneration(),
                'require_published_match' => $report->requirePublishedMatch(),
                'has_active_generation' => $status->hasActiveGeneration(),
                'published_matches_effective' => $status->publishedMatchesEffective(),
            ],
            alerts: array_map(
                static fn (string $message): array => ['message' => $message],
                $report->violations(),
            ),
        ));
    }
}
