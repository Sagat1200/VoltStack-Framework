<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Status;

use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationArtifact;
use VoltStack\Runtime\Budget\RuntimeBudgetBaseline;

final readonly class RuntimeStatusReport
{
    /**
     * @param list<string> $supportedDrivers
     * @param list<string> $alerts
     */
    public function __construct(
        private string $driver,
        private int $maxRequests,
        private bool $persistent,
        private bool $concurrent,
        private bool $streaming,
        private bool $drainControl,
        private bool $nativeHttp,
        private string $capabilityEvidenceLevel,
        private bool $nativeIntegrationVerified,
        private array $capabilityEvidenceNotes,
        private RuntimeBudgetBaseline $recommendedBudget,
        private RuntimeRolloutReadinessReport $rolloutReadiness,
        private ?RuntimeBudgetCalibrationArtifact $activeCalibration = null,
        private array $supportedDrivers = [],
        private array $alerts = [],
    ) {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function maxRequests(): int
    {
        return $this->maxRequests;
    }

    public function persistent(): bool
    {
        return $this->persistent;
    }

    public function concurrent(): bool
    {
        return $this->concurrent;
    }

    public function streaming(): bool
    {
        return $this->streaming;
    }

    public function drainControl(): bool
    {
        return $this->drainControl;
    }

    public function nativeHttp(): bool
    {
        return $this->nativeHttp;
    }

    public function capabilityEvidenceLevel(): string
    {
        return $this->capabilityEvidenceLevel;
    }

    public function nativeIntegrationVerified(): bool
    {
        return $this->nativeIntegrationVerified;
    }

    /**
     * @return list<string>
     */
    public function capabilityEvidenceNotes(): array
    {
        return $this->capabilityEvidenceNotes;
    }

    public function recommendedBudget(): RuntimeBudgetBaseline
    {
        return $this->recommendedBudget;
    }

    public function rolloutReadiness(): RuntimeRolloutReadinessReport
    {
        return $this->rolloutReadiness;
    }

    public function activeCalibration(): ?RuntimeBudgetCalibrationArtifact
    {
        return $this->activeCalibration;
    }

    /**
     * @return list<string>
     */
    public function supportedDrivers(): array
    {
        return $this->supportedDrivers;
    }

    /**
     * @return list<string>
     */
    public function alerts(): array
    {
        return $this->alerts;
    }

    public function healthy(): bool
    {
        return $this->alerts === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'healthy' => $this->healthy(),
            'max_requests' => $this->maxRequests,
            'persistent' => $this->persistent,
            'concurrent' => $this->concurrent,
            'streaming' => $this->streaming,
            'drain_control' => $this->drainControl,
            'native_http' => $this->nativeHttp,
            'capability_evidence' => [
                'level' => $this->capabilityEvidenceLevel,
                'native_integration_verified' => $this->nativeIntegrationVerified,
                'notes' => $this->capabilityEvidenceNotes,
            ],
            'recommended_budget' => $this->recommendedBudget->toArray(),
            'rollout_readiness' => $this->rolloutReadiness->toArray(),
            'active_calibration' => $this->activeCalibration?->toArray(),
            'supported_drivers' => $this->supportedDrivers,
            'alerts' => $this->alerts,
        ];
    }
}
