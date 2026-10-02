<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface ThrottleDistributedStorageInterface
{
    public function currentCount(string $bucketKey, ?int $now = null): int;

    public function increment(string $bucketKey, int $windowSeconds, ?int $now = null): int;

    public function reset(string $bucketKey): void;
}
