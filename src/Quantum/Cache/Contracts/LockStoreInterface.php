<?php

declare(strict_types=1);

namespace Quantum\Cache\Contracts;

/**
 * Backend store for distributed locks. The store MUST provide create-if-absent
 * + expiry as an indivisible operation. renew() and release() MUST verify the
 * owner token atomically so a lease never deletes a lock owned by another
 * process.
 */
interface LockStoreInterface
{
    /**
     * Acquires the lock only if it is not currently held (or expired).
     *
     * @param string $name     normalized lock name
     * @param string $owner    opaque owner token
     * @param positive-int $ttlMs lease TTL in milliseconds
     *
     * @return bool true if acquired, false if held by another owner
     */
    public function acquire(string $name, string $owner, int $ttlMs): bool;

    /**
     * Renews the lease only if the current owner matches $owner.
     *
     * @param string $name
     * @param string $owner
     * @param positive-int $ttlMs
     *
     * @return bool true if renewed, false if not owned or expired
     */
    public function renew(string $name, string $owner, int $ttlMs): bool;

    /**
     * Releases the lock only if the current owner matches $owner.
     *
     * @param string $name
     * @param string $owner
     *
     * @return bool true if released (or already gone), false if owned by another
     */
    public function release(string $name, string $owner): bool;

    /**
     * Returns whether the lock is currently held (regardless of owner).
     */
    public function isHeld(string $name): bool;

    /**
     * Force-releases a lock regardless of owner. Intended for privileged CLI
     * only, with audit. Default stores MUST NOT expose this in normal flows.
     */
    public function forceRelease(string $name): bool;
}
