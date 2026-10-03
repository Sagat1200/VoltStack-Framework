<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Budget;

use Quantum\Bootstrap\BootstrapResult;
use Quantum\Bootstrap\Telemetry\BootstrapPhaseProfile;

final class BootstrapBudgetEvaluator
{
    public function evaluate(BootstrapResult $result, BootstrapBudget $budget): BootstrapBudgetReport
    {
        $phaseDurations = [];
        $violations = [];
        $totalDuration = 0.0;

        foreach ($result->phaseProfiles() as $profile) {
            $phaseDurations[$profile->phase()->value] = $profile->durationMs();
            $totalDuration += $profile->durationMs();
            $phaseMaximum = $budget->maximumFor($profile->phase());

            if ($phaseMaximum !== null && $profile->durationMs() > $phaseMaximum) {
                $violations[] = sprintf(
                    'La fase %s excedio su budget (%.3f ms > %.3f ms).',
                    $profile->phase()->value,
                    $profile->durationMs(),
                    $phaseMaximum,
                );
            }

            if (! $profile->successful()) {
                $violations[] = sprintf(
                    'La fase %s termino marcada como fallida.',
                    $profile->phase()->value,
                );
            }
        }

        $totalMaximum = $budget->totalMaximumMs();

        if ($totalMaximum !== null && $totalDuration > $totalMaximum) {
            $violations[] = sprintf(
                'El bootstrap excedio su budget total (%.3f ms > %.3f ms).',
                $totalDuration,
                $totalMaximum,
            );
        }

        return new BootstrapBudgetReport(
            totalDurationMs: $totalDuration,
            totalMaximumMs: $totalMaximum,
            phaseDurationsMs: $phaseDurations,
            phaseMaximumsMs: $budget->phaseMaximumsMs(),
            violations: $violations,
        );
    }
}
