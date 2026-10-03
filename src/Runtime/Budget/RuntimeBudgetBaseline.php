<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

final readonly class RuntimeBudgetBaseline
{
    public function __construct(
        private string $driver,
        private ?float $totalMaximumMs,
        private ?float $requestMaximumMs,
        private string $source,
    ) {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function totalMaximumMs(): ?float
    {
        return $this->totalMaximumMs;
    }

    public function requestMaximumMs(): ?float
    {
        return $this->requestMaximumMs;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function toBudget(): RuntimeBudget
    {
        return RuntimeBudget::fromValues(
            totalMaximumMs: $this->totalMaximumMs,
            requestMaximumMs: $this->requestMaximumMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'total_budget_ms' => $this->totalMaximumMs,
            'request_budget_ms' => $this->requestMaximumMs,
            'source' => $this->source,
        ];
    }
}
