<?php

declare(strict_types=1);

namespace Quantum\Cache;

use Quantum\Cache\Contracts\LockStoreInterface;

/**
 * Coordinates lock acquisition, renewal and release. The manager generates
 * opaque owner tokens (random) and normalizes lock names. It never reuses
 * owner tokens across leases. Locks are not reentrant by default; the same
 * scope must reuse an existing lease explicitly or receive null.
 *
 * A lease does NOT substitute a transactional constraint nor guarantee
 * exactly-once business execution. Partitions and pauses may allow the
 * previous process to keep running after expiry.
 */
final class LockManager
{
    public function __construct(
        private readonly LockStoreInterface $store,
    ) {
    }

    /**
     * Attempts to acquire a lock. Returns null if the lock is held by another
     * owner.
     *
     * @param string $name      lock name (will be normalized)
     * @param positive-int $ttlMs lease TTL in milliseconds
     * @param positive-int $waitMs  maximum wait time in milliseconds (busy-wait)
     * @param positive-int $retryMs retry interval in milliseconds
     */
    public function acquire(string $name, int $ttlMs, int $waitMs = 0, int $retryMs = 10): ?Lease
    {
        $name = $this->normalizeName($name);
        $owner = bin2hex(random_bytes(16));
        $deadline = $this->nowMs() + $waitMs;

        do {
            if ($this->store->acquire($name, $owner, $ttlMs)) {
                return new Lease(
                    name: $name,
                    owner: $owner,
                    deadlineAtMs: $this->nowMs() + $ttlMs,
                    fencingToken: null,
                    store: $this->store,
                    ttlMs: $ttlMs,
                );
            }

            if ($waitMs <= 0) {
                return null;
            }

            usleep($retryMs * 1000);
        } while ($this->nowMs() < $deadline);

        return null;
    }

    /**
     * Whether the lock is currently held (regardless of owner).
     */
    public function isHeld(string $name): bool
    {
        return $this->store->isHeld($this->normalizeName($name));
    }

    /**
     * Force-release a lock regardless of owner. Privileged operation; should
     * only be used from CLI with audit.
     */
    public function forceRelease(string $name): bool
    {
        return $this->store->forceRelease($this->normalizeName($name));
    }

    private function normalizeName(string $name): string
    {
        return trim($name);
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
