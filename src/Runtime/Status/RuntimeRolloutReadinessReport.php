<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Status;

final readonly class RuntimeRolloutReadinessReport
{
    /**
     * @param list<string> $gaps
     */
    public function __construct(
        private bool $ready,
        private string $strategy,
        private string $budgetSource,
        private bool $requiresDrainControl,
        private bool $requiresEmpiricalBudget,
        private array $gaps = [],
    ) {
    }

    public function ready(): bool
    {
        return $this->ready;
    }

    public function strategy(): string
    {
        return $this->strategy;
    }

    public function budgetSource(): string
    {
        return $this->budgetSource;
    }

    public function requiresDrainControl(): bool
    {
        return $this->requiresDrainControl;
    }

    public function requiresEmpiricalBudget(): bool
    {
        return $this->requiresEmpiricalBudget;
    }

    /**
     * @return list<string>
     */
    public function gaps(): array
    {
        return $this->gaps;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'strategy' => $this->strategy,
            'budget_source' => $this->budgetSource,
            'requires_drain_control' => $this->requiresDrainControl,
            'requires_empirical_budget' => $this->requiresEmpiricalBudget,
            'gaps' => $this->gaps,
        ];
    }
}
