<?php

declare(strict_types=1);

namespace Quantum\Cache\Contracts;

use DateInterval;
use DateTimeInterface;

/**
 * Atomic increment capability for cache stores that natively support it
 * (e.g. Redis, Memcached, Predis adapters). A store may implement this
 * interface so consistency drivers can bump versions with compare-and-swap
 * semantics instead of a read+modify+write race.
 */
interface AtomicIncrementableStoreInterface extends StoreInterface
{
    /**
     * Atomically increments the integer value stored at $key by $step,
     * initializing it to $initial if it does not already exist.
     *
     * @param positive-int $step
     * @param int<0, max> $initial
     *
     * @return int<0, max> The new value after increment.
     */
    public function incrementInt(
        string $key,
        int $step = 1,
        int $initial = 1,
        DateInterval|DateTimeInterface|int|null $ttl = null,
    ): int;
}
