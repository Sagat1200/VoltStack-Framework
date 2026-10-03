<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Budget;

use Quantum\Bootstrap\Phase\BootstrapState;

final readonly class BootstrapBudget
{
    /**
     * @param array<string, float> $phaseMaximumsMs
     */
    public function __construct(
        private ?float $totalMaximumMs = null,
        private array $phaseMaximumsMs = [],
    ) {
    }

    /**
     * @param array<string, float|int|string> $phaseMaximumsMs
     */
    public static function fromValues(?float $totalMaximumMs = null, array $phaseMaximumsMs = []): self
    {
        $normalized = [];

        foreach ($phaseMaximumsMs as $phase => $limit) {
            $phase = strtoupper(trim((string) $phase));

            if ($phase === '') {
                continue;
            }

            $numeric = (float) $limit;

            if ($numeric <= 0) {
                continue;
            }

            $normalized[$phase] = $numeric;
        }

        $totalMaximumMs = $totalMaximumMs !== null && $totalMaximumMs > 0
            ? $totalMaximumMs
            : null;

        return new self($totalMaximumMs, $normalized);
    }

    public function totalMaximumMs(): ?float
    {
        return $this->totalMaximumMs;
    }

    public function maximumFor(BootstrapState|string $phase): ?float
    {
        $phaseKey = $phase instanceof BootstrapState ? $phase->value : strtoupper(trim($phase));

        return $this->phaseMaximumsMs[$phaseKey] ?? null;
    }

    /**
     * @return array<string, float>
     */
    public function phaseMaximumsMs(): array
    {
        return $this->phaseMaximumsMs;
    }
}
