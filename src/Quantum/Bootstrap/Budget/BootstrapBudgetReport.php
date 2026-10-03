<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Budget;

final readonly class BootstrapBudgetReport
{
    /**
     * @param array<string, float> $phaseDurationsMs
     * @param array<string, float> $phaseMaximumsMs
     * @param list<string> $violations
     */
    public function __construct(
        private float $totalDurationMs,
        private ?float $totalMaximumMs,
        private array $phaseDurationsMs,
        private array $phaseMaximumsMs,
        private array $violations = [],
    ) {
    }

    public function totalDurationMs(): float
    {
        return $this->totalDurationMs;
    }

    public function totalMaximumMs(): ?float
    {
        return $this->totalMaximumMs;
    }

    /**
     * @return array<string, float>
     */
    public function phaseDurationsMs(): array
    {
        return $this->phaseDurationsMs;
    }

    /**
     * @return array<string, float>
     */
    public function phaseMaximumsMs(): array
    {
        return $this->phaseMaximumsMs;
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    public function passed(): bool
    {
        return $this->violations === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'total_duration_ms' => $this->totalDurationMs,
            'total_budget_ms' => $this->totalMaximumMs,
            'phase_durations_ms' => $this->phaseDurationsMs,
            'phase_budgets_ms' => $this->phaseMaximumsMs,
            'violations' => $this->violations,
        ];
    }
}
