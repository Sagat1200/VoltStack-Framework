<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

use InvalidArgumentException;
use VoltStack\Runtime\Smoke\RuntimeSmokeChecker;

final class RuntimeBudgetCalibrator
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param list<string> $requestDefinitions
     */
    public function run(
        ?string $driver = null,
        string $profile = 'release',
        array $requestDefinitions = [],
        int $warmupIterations = 2,
        int $measuredIterations = 10,
        float $safetyMultiplier = 1.25,
        ?string $artifactDirectory = null,
    ): RuntimeBudgetCalibrationReport {
        if ($warmupIterations < 0) {
            throw new InvalidArgumentException('warmupIterations no puede ser negativo.');
        }

        if ($measuredIterations < 1) {
            throw new InvalidArgumentException('measuredIterations debe ser mayor o igual a 1.');
        }

        if ($safetyMultiplier < 1.0) {
            throw new InvalidArgumentException('safetyMultiplier debe ser mayor o igual a 1.0.');
        }

        $checker = new RuntimeSmokeChecker($this->basePath);

        for ($index = 0; $index < $warmupIterations; $index++) {
            $checker->run(
                driver: $driver,
                profile: $profile,
                requestDefinitions: $requestDefinitions,
                budget: null,
                artifactDirectory: $artifactDirectory,
                useBudgetBaseline: false,
            );
        }

        $currentBaseline = null;
        $samples = [];
        $failures = [];

        for ($iteration = 1; $iteration <= $measuredIterations; $iteration++) {
            $report = $checker->run(
                driver: $driver,
                profile: $profile,
                requestDefinitions: $requestDefinitions,
                budget: null,
                artifactDirectory: $artifactDirectory,
                useBudgetBaseline: false,
            );

            $currentBaseline ??= $report->budgetBaseline();

            if (! $report->passed()) {
                $failures[] = [
                    'iteration' => $iteration,
                    'violations' => $report->violations(),
                ];

                continue;
            }

            $samples[] = [
                'iteration' => $iteration,
                'total_duration_ms' => $report->budget()->totalDurationMs(),
                'max_request_duration_ms' => $report->budget()->maxRequestDurationMs(),
                'request_count' => $report->budget()->requestCount(),
            ];
        }

        if ($currentBaseline === null) {
            $currentBaseline = new RuntimeBudgetBaseline(
                driver: strtolower(trim($driver ?? 'unknown')),
                totalMaximumMs: null,
                requestMaximumMs: null,
                source: 'unavailable',
            );
        }

        $totalStats = $this->stats(array_map(
            static fn(array $sample): float => $sample['total_duration_ms'],
            $samples,
        ));
        $requestStats = $this->stats(array_map(
            static fn(array $sample): float => $sample['max_request_duration_ms'],
            $samples,
        ));

        $recommendedBudget = new RuntimeBudgetBaseline(
            driver: $currentBaseline->driver(),
            totalMaximumMs: $totalStats !== null ? round($totalStats['max'] * $safetyMultiplier, 3) : null,
            requestMaximumMs: $requestStats !== null ? round($requestStats['max'] * $safetyMultiplier, 3) : null,
            source: 'empirical-calibration',
        );

        return new RuntimeBudgetCalibrationReport(
            driver: $currentBaseline->driver(),
            profile: $profile,
            requestDefinitions: $requestDefinitions,
            warmupIterations: $warmupIterations,
            measuredIterations: $measuredIterations,
            safetyMultiplier: $safetyMultiplier,
            currentBaseline: $currentBaseline,
            recommendedBudget: $recommendedBudget,
            samples: $samples,
            failures: $failures,
            totalStats: $totalStats,
            requestStats: $requestStats,
        );
    }

    /**
     * @param list<float> $values
     * @return array{min:float,avg:float,p95:float,max:float}|null
     */
    private function stats(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $sum = array_sum($values);
        $index = max(0, (int) ceil($count * 0.95) - 1);

        return [
            'min' => $values[0],
            'avg' => $sum / $count,
            'p95' => $values[$index],
            'max' => $values[$count - 1],
        ];
    }
}
