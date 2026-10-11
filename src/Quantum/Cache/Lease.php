<?php

declare(strict_types=1);

namespace Quantum\Cache;

use Quantum\Cache\Contracts\LockStoreInterface;

/**
 * A lease represents a successfully acquired lock. It carries the owner token
 * (a credential), the deadline (monotonic local time in milliseconds) and an
 * optional fencing token. The lease MUST verify ownership atomically on
 * renew() and release(). A failed/unknown renewal marks the lease as Lost so
 * it stops publishing.
 */
final class Lease
{
    private bool $released = false;

    /**
     * @param string $name           normalized lock name
     * @param string $owner          opaque owner token (credential, redacted in logs)
     * @param int    $deadlineAtMs   monotonic local deadline in milliseconds
     * @param int|null $fencingToken optional monotonic fencing token
     * @param LockStoreInterface $store
     * @param int $ttlMs             original TTL in milliseconds (for renew)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $owner,
        public int $deadlineAtMs,
        public readonly ?int $fencingToken,
        private readonly LockStoreInterface $store,
        private readonly int $ttlMs,
    ) {
    }

    public function isLost(int $nowMs): bool
    {
        return $this->released || $nowMs >= $this->deadlineAtMs;
    }

    public function isHeld(int $nowMs): bool
    {
        return ! $this->isLost($nowMs);
    }

    /**
     * Renews the lease for $ttlMs milliseconds. Renewal MUST verify ownership
     * atomically. If the renewal fails or returns Unknown, the lease is marked
     * as lost and stops publishing.
     *
     * @param positive-int $ttlMs
     */
    public function renew(int $ttlMs = 0): bool
    {
        $effective = $ttlMs > 0 ? $ttlMs : $this->ttlMs;
        $now = (int) (microtime(true) * 1000);

        if ($this->isLost($now)) {
            return false;
        }

        $result = $this->store->renew($this->name, $this->owner, $effective);

        if (! $result) {
            $this->released = true;

            return false;
        }

        $this->deadlineAtMs = $now + $effective;

        return true;
    }

    /**
     * Releases the lease atomically. Release is idempotent and returns false
     * if the lease is no longer held. It MUST NOT delete a lock owned by
     * another process.
     */
    public function release(): bool
    {
        if ($this->released) {
            return false;
        }

        $this->released = true;

        return $this->store->release($this->name, $this->owner);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'owner' => substr($this->owner, 0, 8) . '…',
            'deadline_at_ms' => $this->deadlineAtMs,
            'fencing_token' => $this->fencingToken,
            'lost' => $this->released,
        ];
    }
}
