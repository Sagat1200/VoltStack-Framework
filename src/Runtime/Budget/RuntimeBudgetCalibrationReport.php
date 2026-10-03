<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

final readonly class RuntimeBudgetCalibrationReport
{
    /**
     * @param list<string> $requestDefinitions
     * @param list<array{iteration:int,total_duration_ms:float,max_request_duration_ms:float,request_count:int}> $samples
     * @param list<array{iteration:int,violations:list<string>}> $failures
     * @param array{min:float,avg:float,p95:float,max:float}|null $totalStats
     * @param array{min:float,avg:float,p95:float,max:float}|null $requestStats
     */
    public function __construct(
        private string $driver,
        private string $profile,
        private array $requestDefinitions,
        private int $warmupIterations,
        private int $measuredIterations,
        private float $safetyMultiplier,
        private RuntimeBudgetBaseline $currentBaseline,
        private RuntimeBudgetBaseline $recommendedBudget,
        private array $samples,
        private array $failures,
        private ?array $totalStats,
        private ?array $requestStats,
    ) {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    /**
     * @return list<string>
     */
    public function requestDefinitions(): array
    {
        return $this->requestDefinitions;
    }

    public function warmupIterations(): int
    {
        return $this->warmupIterations;
    }

    public function measuredIterations(): int
    {
        return $this->measuredIterations;
    }

    public function safetyMultiplier(): float
    {
        return $this->safetyMultiplier;
    }

    public function currentBaseline(): RuntimeBudgetBaseline
    {
        return $this->currentBaseline;
    }

    public function recommendedBudget(): RuntimeBudgetBaseline
    {
        return $this->recommendedBudget;
    }

    /**
     * @return list<array{iteration:int,total_duration_ms:float,max_request_duration_ms:float,request_count:int}>
     */
    public function samples(): array
    {
        return $this->samples;
    }

    /**
     * @return list<array{iteration:int,violations:list<string>}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @return array{min:float,avg:float,p95:float,max:float}|null
     */
    public function totalStats(): ?array
    {
        return $this->totalStats;
    }

    /**
     * @return array{min:float,avg:float,p95:float,max:float}|null
     */
    public function requestStats(): ?array
    {
        return $this->requestStats;
    }

    public function successfulIterations(): int
    {
        return count($this->samples);
    }

    public function failedIterations(): int
    {
        return count($this->failures);
    }

    public function passed(): bool
    {
        return $this->successfulIterations() > 0 && $this->failedIterations() === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'driver' => $this->driver,
            'profile' => $this->profile,
            'request_definitions' => $this->requestDefinitions,
            'warmup_iterations' => $this->warmupIterations,
            'measured_iterations' => $this->measuredIterations,
            'successful_iterations' => $this->successfulIterations(),
            'failed_iterations' => $this->failedIterations(),
            'safety_multiplier' => $this->safetyMultiplier,
            'current_baseline' => $this->currentBaseline->toArray(),
            'recommended_budget' => $this->recommendedBudget->toArray(),
            'statistics' => [
                'total_duration_ms' => $this->totalStats,
                'max_request_duration_ms' => $this->requestStats,
            ],
            'samples' => $this->samples,
            'failures' => $this->failures,
        ];
    }
}
