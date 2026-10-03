<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Context;

final readonly class WorkerContext
{
    public function __construct(
        private string $workerId,
        private string $driver,
        private int $maxRequests,
        private int $startedAt,
    ) {
    }

    public static function create(string $driver, int $maxRequests): self
    {
        return new self(
            workerId: bin2hex(random_bytes(8)),
            driver: $driver,
            maxRequests: max(1, $maxRequests),
            startedAt: time(),
        );
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function maxRequests(): int
    {
        return $this->maxRequests;
    }

    public function startedAt(): int
    {
        return $this->startedAt;
    }
}
