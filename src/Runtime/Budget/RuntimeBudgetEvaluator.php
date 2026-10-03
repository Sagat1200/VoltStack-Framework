<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

use VoltStack\Runtime\Smoke\RuntimeSmokeRequestReport;

final class RuntimeBudgetEvaluator
{
    /**
     * @param list<RuntimeSmokeRequestReport> $requests
     */
    public function evaluate(array $requests, RuntimeBudget $budget): RuntimeBudgetReport
    {
        $totalDuration = 0.0;
        $maxDuration = 0.0;
        $violations = [];

        foreach ($requests as $request) {
            $totalDuration += $request->durationMs();
            $maxDuration = max($maxDuration, $request->durationMs());

            $requestMaximum = $budget->requestMaximumMs();

            if ($requestMaximum !== null && $request->durationMs() > $requestMaximum) {
                $violations[] = sprintf(
                    'La request %s %s excedio su budget (%.3f ms > %.3f ms).',
                    $request->method(),
                    $request->path(),
                    $request->durationMs(),
                    $requestMaximum,
                );
            }
        }

        $totalMaximum = $budget->totalMaximumMs();

        if ($totalMaximum !== null && $totalDuration > $totalMaximum) {
            $violations[] = sprintf(
                'El runtime smoke-check excedio su budget total (%.3f ms > %.3f ms).',
                $totalDuration,
                $totalMaximum,
            );
        }

        return new RuntimeBudgetReport(
            requestCount: count($requests),
            totalDurationMs: $totalDuration,
            maxRequestDurationMs: $maxDuration,
            totalMaximumMs: $totalMaximum,
            requestMaximumMs: $budget->requestMaximumMs(),
            violations: $violations,
        );
    }
}
