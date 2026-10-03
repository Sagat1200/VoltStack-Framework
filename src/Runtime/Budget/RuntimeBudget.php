<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

final readonly class RuntimeBudget
{
    public function __construct(
        private ?float $totalMaximumMs = null,
        private ?float $requestMaximumMs = null,
    ) {
    }

    public static function fromValues(?float $totalMaximumMs = null, ?float $requestMaximumMs = null): self
    {
        $totalMaximumMs = $totalMaximumMs !== null && $totalMaximumMs > 0
            ? $totalMaximumMs
            : null;
        $requestMaximumMs = $requestMaximumMs !== null && $requestMaximumMs > 0
            ? $requestMaximumMs
            : null;

        return new self(
            totalMaximumMs: $totalMaximumMs,
            requestMaximumMs: $requestMaximumMs,
        );
    }

    public function totalMaximumMs(): ?float
    {
        return $this->totalMaximumMs;
    }

    public function requestMaximumMs(): ?float
    {
        return $this->requestMaximumMs;
    }
}
