<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Contracts\OidcJwksCacheInterface;

final class InMemoryOidcJwksCache implements OidcJwksCacheInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $keys = [];

    private int $fetchedAt = 0;

    public function __construct(
        private readonly int $ttlSeconds = 0,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getKey(string $kid): ?array
    {
        if (trim($kid) === '') {
            return null;
        }
        if ($this->ttlSeconds > 0 && $this->hasExpired()) {
            return null;
        }
        return $this->keys[$kid] ?? null;
    }

    /**
     * @param array<string, mixed> $jwk
     */
    public function saveKey(string $kid, array $jwk): void
    {
        if (trim($kid) === '') {
            return;
        }
        $this->keys[$kid] = $jwk;
    }

    public function markFetchedNow(int $ts = 0): void
    {
        $this->fetchedAt = $ts > 0 ? $ts : time();
    }

    public function getFetchedAt(): int
    {
        return $this->fetchedAt;
    }

    public function getTtlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function hasExpired(int $nowTs = 0): bool
    {
        if ($this->ttlSeconds <= 0) {
            return false;
        }
        $now = $nowTs > 0 ? $nowTs : time();
        return $this->fetchedAt > 0 && ($now - $this->fetchedAt) > $this->ttlSeconds;
    }

    public function clear(): void
    {
        $this->keys = [];
        $this->fetchedAt = 0;
    }
}
