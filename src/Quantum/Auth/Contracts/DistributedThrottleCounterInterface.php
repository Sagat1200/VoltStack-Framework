<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

/**
 * @internal V2 interface distribuida para counters throttle.
 *           Implementación por defecto: null/null-implementation cuando throttle.distributed=false.
 *           Obligatoria para ambientes multi-web-node distribuidos; fallback InMemory cuando no.
 */
interface DistributedThrottleCounterInterface
{
    public function currentCount(string $bucketKey, ?int $now = null): int;

    public function increment(string $bucketKey, int $windowSeconds, ?int $now = null): int;

    public function reset(string $bucketKey): void;
}
