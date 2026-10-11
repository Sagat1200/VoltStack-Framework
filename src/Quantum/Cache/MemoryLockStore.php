<?php

declare(strict_types=1);

namespace Quantum\Cache;

use Quantum\Cache\Contracts\LockStoreInterface;

/**
 * In-process lock store backed by an in-memory array. Suitable for unit
 * tests and single-process coordination. Does NOT provide cross-process
 * exclusion; use FileLockStore or a distributed store for that.
 */
final class MemoryLockStore implements LockStoreInterface
{
    /**
     * @var array<string, array{owner: string, expires_at_ms: int}>
     */
    private array $locks = [];

    public function acquire(string $name, string $owner, int $ttlMs): bool
    {
        $now = $this->nowMs();

        if (isset($this->locks[$name]) && $this->locks[$name]['expires_at_ms'] > $now) {
            return false;
        }

        $this->locks[$name] = [
            'owner' => $owner,
            'expires_at_ms' => $now + $ttlMs,
        ];

        return true;
    }

    public function renew(string $name, string $owner, int $ttlMs): bool
    {
        $now = $this->nowMs();

        if (! isset($this->locks[$name])) {
            return false;
        }

        if ($this->locks[$name]['owner'] !== $owner) {
            return false;
        }

        if ($this->locks[$name]['expires_at_ms'] <= $now) {
            return false;
        }

        $this->locks[$name]['expires_at_ms'] = $now + $ttlMs;

        return true;
    }

    public function release(string $name, string $owner): bool
    {
        if (! isset($this->locks[$name])) {
            return true;
        }

        if ($this->locks[$name]['owner'] !== $owner) {
            return false;
        }

        unset($this->locks[$name]);

        return true;
    }

    public function isHeld(string $name): bool
    {
        $now = $this->nowMs();

        return isset($this->locks[$name]) && $this->locks[$name]['expires_at_ms'] > $now;
    }

    public function forceRelease(string $name): bool
    {
        unset($this->locks[$name]);

        return true;
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
