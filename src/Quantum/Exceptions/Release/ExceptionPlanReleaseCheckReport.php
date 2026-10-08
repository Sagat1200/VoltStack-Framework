<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Release;

use Quantum\Exceptions\Diagnostics\ExceptionPlanStatusReport;

final readonly class ExceptionPlanReleaseCheckReport
{
    private const ALERT_NO_PUBLISHED_PLAN = 'No hay un plan de excepciones publicado.';
    private const ALERT_PUBLISHED_MISMATCH = 'El plan de excepciones efectivo difiere del plan publicado.';
    private const ALERT_INCOMPATIBLE_PLAN = 'El plan de excepciones publicado es incompatible con el runtime o la version de PHP actual.';
    private const ALERT_INVALID_PLAN = 'El plan de excepciones publicado es invalido, esta corrupto o no pudo cargarse.';

    public function __construct(
        private ExceptionPlanStatusReport $status,
        private bool $requirePublishedPlan = true,
        private bool $requirePublishedMatch = true,
        private bool $requireCompatiblePlan = true,
    ) {
    }

    public function status(): ExceptionPlanStatusReport
    {
        return $this->status;
    }

    public function requirePublishedPlan(): bool
    {
        return $this->requirePublishedPlan;
    }

    public function requirePublishedMatch(): bool
    {
        return $this->requirePublishedMatch;
    }

    public function requireCompatiblePlan(): bool
    {
        return $this->requireCompatiblePlan;
    }

    public function passed(): bool
    {
        return $this->violations() === [];
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->status->alerts() as $alert) {
            if ($alert === self::ALERT_NO_PUBLISHED_PLAN && ! $this->requirePublishedPlan) {
                continue;
            }

            if ($alert === self::ALERT_PUBLISHED_MISMATCH && ! $this->requirePublishedMatch) {
                continue;
            }

            if ($alert === self::ALERT_INCOMPATIBLE_PLAN && ! $this->requireCompatiblePlan) {
                continue;
            }

            if ($alert === self::ALERT_INVALID_PLAN) {
                $violations[] = $alert;
                continue;
            }

            $violations[] = $alert;
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'require_published_plan' => $this->requirePublishedPlan,
            'require_published_match' => $this->requirePublishedMatch,
            'require_compatible_plan' => $this->requireCompatiblePlan,
            'violations' => $this->violations(),
            'status' => $this->status->toArray(),
        ];
    }
}
