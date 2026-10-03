<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Telemetry;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Bootstrap\Context\BootstrapContext;
use Quantum\Bootstrap\Phase\BootstrapState;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;

final class BootstrapPhaseTelemetryEmitter
{
    public function __construct(
        private readonly TelemetryManagerInterface $telemetry,
        private readonly string $traceId,
    ) {
    }

    public function emitPhase(
        BootstrapState $phase,
        float $durationMs,
        ApplicationPlan $plan,
        ?BootstrapContext $context = null,
        bool $successful = true,
    ): void {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'bootstrap_phase',
            type: 'metric',
            source: 'bootstrap',
            occurredAt: date('c'),
            payload: [
                'phase' => $phase->value,
                'duration_ms' => $durationMs,
                'successful' => $successful,
                'environment' => $context?->environment() ?? $plan->environment(),
                'profile' => $context?->profile() ?? $plan->profile(),
            ],
            attributes: [
                'phase' => $phase->value,
                'successful' => $successful,
            ],
            traceId: $this->traceId,
        ));
    }

    /**
     * @param list<BootstrapPhaseProfile> $profiles
     */
    public function emitSummary(
        array $profiles,
        ApplicationPlan $plan,
        ?BootstrapContext $context = null,
        int $providerCount = 0,
        int $warmerCount = 0,
    ): void {
        $this->telemetry->emit(new TelemetrySignal(
            name: 'bootstrap_profile',
            type: 'event',
            source: 'bootstrap',
            occurredAt: date('c'),
            payload: [
                'environment' => $context?->environment() ?? $plan->environment(),
                'profile' => $context?->profile() ?? $plan->profile(),
                'provider_count' => $providerCount,
                'warmer_count' => $warmerCount,
                'phases' => array_map(
                    static fn(BootstrapPhaseProfile $profile): array => $profile->toArray(),
                    $profiles,
                ),
            ],
            attributes: [
                'phase_count' => count($profiles),
                'provider_count' => $providerCount,
                'warmer_count' => $warmerCount,
            ],
            traceId: $this->traceId,
        ));
    }
}
