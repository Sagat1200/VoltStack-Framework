<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

final readonly class RuntimeBudgetReport
{
    /**
     * @param list<string> $violations
     */
    public function __construct(
        private int $requestCount,
        private float $totalDurationMs,
        private float $maxRequestDurationMs,
        private ?float $totalMaximumMs,
        private ?float $requestMaximumMs,
        private array $violations = [],
    ) {
    }

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    public function totalDurationMs(): float
    {
        return $this->totalDurationMs;
    }

    public function maxRequestDurationMs(): float
    {
        return $this->maxRequestDurationMs;
    }

    public function totalMaximumMs(): ?float
    {
        return $this->totalMaximumMs;
    }

    public function requestMaximumMs(): ?float
    {
        return $this->requestMaximumMs;
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
            'request_count' => $this->requestCount,
            'total_duration_ms' => $this->totalDurationMs,
            'max_request_duration_ms' => $this->maxRequestDurationMs,
            'total_budget_ms' => $this->totalMaximumMs,
            'request_budget_ms' => $this->requestMaximumMs,
            'violations' => $this->violations,
        ];
    }
}
