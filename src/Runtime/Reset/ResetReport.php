<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Reset;

use Throwable;

final readonly class ResetReport
{
    /**
     * @param list<Throwable> $errors
     */
    public function __construct(
        private int $executedCount,
        private bool $successful,
        private array $errors = [],
    ) {
    }

    public function executedCount(): int
    {
        return $this->executedCount;
    }

    public function successful(): bool
    {
        return $this->successful;
    }

    /**
     * @return list<Throwable>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
