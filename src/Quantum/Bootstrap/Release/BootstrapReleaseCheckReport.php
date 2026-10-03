<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Release;

use Quantum\Bootstrap\BootstrapResult;
use Quantum\Bootstrap\Budget\BootstrapBudgetReport;

final readonly class BootstrapReleaseCheckReport
{
    public function __construct(
        private BootstrapResult $result,
        private BootstrapBudgetReport $budget,
        private string $profile,
    ) {
    }

    public function result(): BootstrapResult
    {
        return $this->result;
    }

    public function budget(): BootstrapBudgetReport
    {
        return $this->budget;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function passed(): bool
    {
        return $this->result->ready() && $this->budget->passed();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'profile' => $this->profile,
            'state' => $this->result->state()->value,
            'ready' => $this->result->ready(),
            'warmed' => $this->result->warmed(),
            'warmed_by' => $this->result->warmedBy(),
            'generation_id' => $this->result->generationId(),
            'manifest_path' => $this->result->manifestPath(),
            'budget' => $this->budget->toArray(),
            'phase_profiles' => array_map(
                static fn(\Quantum\Bootstrap\Telemetry\BootstrapPhaseProfile $profile): array => $profile->toArray(),
                $this->result->phaseProfiles(),
            ),
        ];
    }
}
