<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

final readonly class ReportBudget
{
    public function __construct(
        public ?int $deadlineMonotonic = null,
        public int $maxBytes = 65536,
        public int $maxAttempts = 1,
    ) {
        if ($deadlineMonotonic !== null && $deadlineMonotonic <= 0) {
            throw new \InvalidArgumentException('ReportBudget deadlineMonotonic must be greater than zero when provided.');
        }

        if ($maxBytes <= 0) {
            throw new \InvalidArgumentException('ReportBudget maxBytes must be greater than zero.');
        }

        if ($maxAttempts <= 0) {
            throw new \InvalidArgumentException('ReportBudget maxAttempts must be greater than zero.');
        }
    }
}
