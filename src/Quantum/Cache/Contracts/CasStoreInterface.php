<?php

declare(strict_types=1);

namespace Quantum\Cache\Contracts;

use DateInterval;
use DateTimeInterface;
use Quantum\Cache\CasResult;
use Quantum\Cache\CasSnapshot;

/**
 * Compare-and-swap capability for cache stores. A store implementing this
 * interface can read a snapshot with an opaque version token and atomically
 * replace the value only if the token still matches. The token includes an
 * incarnation counter so the ABA problem is avoided.
 */
interface CasStoreInterface extends StoreInterface
{
    /**
     * Reads the current value together with its version token.
     *
     * @param string $key
     * @param mixed  $default returned when the key is absent or expired
     *
     * @return CasSnapshot|null null when the entry does not exist
     */
    public function snapshot(string $key, mixed $default = null): ?CasSnapshot;

    /**
     * Atomically replaces the value at $key only if the stored token still
     * matches $expectedToken.
     *
     * @param string $key
     * @param string $expectedToken
     * @param mixed  $value
     * @param DateInterval|DateTimeInterface|int|null $ttl
     *
     * @return CasResult
     */
    public function compareAndSwap(
        string $key,
        string $expectedToken,
        mixed $value,
        DateInterval|DateTimeInterface|int|null $ttl = null,
    ): CasResult;
}
