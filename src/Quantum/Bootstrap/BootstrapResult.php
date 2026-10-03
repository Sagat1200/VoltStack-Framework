<?php

declare(strict_types=1);

namespace Quantum\Bootstrap;

use Quantum\Bootstrap\Context\BootstrapContext;
use Quantum\Bootstrap\Manifest\BootstrapBuildArtifact;
use Quantum\Bootstrap\Phase\BootstrapState;
use Quantum\Bootstrap\Telemetry\BootstrapPhaseProfile;
use VoltStack\Framework\Application;

final readonly class BootstrapResult
{
    /**
     * @param list<class-string> $warmedBy
     * @param list<BootstrapPhaseProfile> $phaseProfiles
     */
    public function __construct(
        private Application $app,
        private ApplicationPlan $plan,
        private ?BootstrapContext $context,
        private BootstrapState $state,
        private bool $warmed,
        private bool $ready,
        private array $warmedBy = [],
        private ?BootstrapBuildArtifact $artifact = null,
        private array $phaseProfiles = [],
    ) {
    }

    public function app(): Application
    {
        return $this->app;
    }

    public function plan(): ApplicationPlan
    {
        return $this->plan;
    }

    public function context(): ?BootstrapContext
    {
        return $this->context;
    }

    public function state(): BootstrapState
    {
        return $this->state;
    }

    public function warmed(): bool
    {
        return $this->warmed;
    }

    public function ready(): bool
    {
        return $this->ready;
    }

    /**
     * @return list<class-string>
     */
    public function warmedBy(): array
    {
        return $this->warmedBy;
    }

    public function artifact(): ?BootstrapBuildArtifact
    {
        return $this->artifact;
    }

    public function generationId(): ?string
    {
        return $this->artifact?->generationId();
    }

    public function manifestPath(): ?string
    {
        return $this->artifact?->manifestPath();
    }

    /**
     * @return list<BootstrapPhaseProfile>
     */
    public function phaseProfiles(): array
    {
        return $this->phaseProfiles;
    }
}
