<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Diagnostics;

use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;

final readonly class ExceptionPlanStatusReport
{
    private const ALERT_NO_PUBLISHED_PLAN = 'No hay un plan de excepciones publicado.';
    private const ALERT_PUBLISHED_MISMATCH = 'El plan de excepciones efectivo difiere del plan publicado.';
    private const ALERT_INCOMPATIBLE_PLAN = 'El plan de excepciones publicado es incompatible con el runtime o la version de PHP actual.';
    private const ALERT_INVALID_PLAN = 'El plan de excepciones publicado es invalido, esta corrupto o no pudo cargarse.';

    public function __construct(
        private ExceptionCompilationPlan $effectivePlan,
        private ?ExceptionCompilationPlan $publishedPlan,
        private string $artifactPath,
        private bool $publishedArtifactExists,
        private ?string $publishedArtifactError = null,
        private bool $publishedCompatible = false,
    ) {
    }

    public function effectivePlan(): ExceptionCompilationPlan
    {
        return $this->effectivePlan;
    }

    public function publishedPlan(): ?ExceptionCompilationPlan
    {
        return $this->publishedPlan;
    }

    public function artifactPath(): string
    {
        return $this->artifactPath;
    }

    public function publishedArtifactExists(): bool
    {
        return $this->publishedArtifactExists;
    }

    public function publishedArtifactError(): ?string
    {
        return $this->publishedArtifactError;
    }

    public function publishedMatchesEffective(): bool
    {
        return $this->publishedPlan instanceof ExceptionCompilationPlan
            && $this->publishedPlan->fingerprint() === $this->effectivePlan->fingerprint();
    }

    public function publishedCompatible(): bool
    {
        return $this->publishedPlan instanceof ExceptionCompilationPlan
            && $this->publishedCompatible
            && $this->publishedArtifactError === null;
    }

    public function healthy(): bool
    {
        return $this->alerts() === [];
    }

    /**
     * @return list<string>
     */
    public function alerts(): array
    {
        $alerts = [];

        if ($this->publishedArtifactError !== null) {
            $alerts[] = self::ALERT_INVALID_PLAN;

            return $alerts;
        }

        if (! $this->publishedArtifactExists) {
            $alerts[] = self::ALERT_NO_PUBLISHED_PLAN;

            return $alerts;
        }

        if (! $this->publishedCompatible()) {
            $alerts[] = self::ALERT_INCOMPATIBLE_PLAN;
        }

        if (! $this->publishedMatchesEffective()) {
            $alerts[] = self::ALERT_PUBLISHED_MISMATCH;
        }

        return $alerts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_path' => $this->artifactPath,
            'published_artifact_exists' => $this->publishedArtifactExists,
            'published_artifact_error' => $this->publishedArtifactError,
            'published_matches_effective' => $this->publishedMatchesEffective(),
            'published_compatible' => $this->publishedCompatible(),
            'effective' => $this->describePlan($this->effectivePlan),
            'published' => $this->publishedPlan !== null ? $this->describePlan($this->publishedPlan) : null,
            'alerts' => $this->alerts(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describePlan(ExceptionCompilationPlan $plan): array
    {
        return [
            'environment' => $plan->environment(),
            'runtime' => $plan->runtime(),
            'debug' => $plan->debug(),
            'fingerprint' => $plan->fingerprint(),
            'policy_revision' => $plan->policyRevision(),
            'php_runtime_version' => $plan->phpRuntimeVersion(),
            'reporters' => $plan->reporterIds(),
            'spa_versions' => $plan->spaVersions(),
        ];
    }
}
