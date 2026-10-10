<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeImmutable;
use Quantum\Cache\Contracts\AtomicIncrementableStoreInterface;
use Quantum\Cache\Contracts\StoreInterface;
use Quantum\Cache\Contracts\VersionAuthorityInterface;

final class CacheVersionAuthority implements VersionAuthorityInterface
{
    private const ENVELOPE_VERSION = 2;

    public function __construct(
        private readonly StoreInterface $store,
        private readonly string $keyPrefix = 'voltstack.version_authority',
        private readonly DateInterval|int|null $defaultTtl = null,
    ) {
        $prefix = trim($this->keyPrefix);

        if ($prefix === '') {
            throw new \InvalidArgumentException('CacheVersionAuthority key prefix cannot be empty.');
        }
    }

    public function currentVersion(string $scope): string
    {
        return 'v' . $this->readEnvelope($this->normalizeScope($scope))['version'];
    }

    public function bump(string $scope, ?string $reason = null): string
    {
        $normalizedScope = $this->normalizeScope($scope);
        $normalizedReason = is_string($reason) ? trim($reason) : '';
        $normalizedReason = $normalizedReason === '' ? null : $normalizedReason;

        if ($this->store instanceof AtomicIncrementableStoreInterface) {
            return 'v' . $this->bumpAtomically($normalizedScope, $normalizedReason);
        }

        $envelope = $this->readEnvelope($normalizedScope);
        $nextVersion = $envelope['version'] + 1;
        $this->writeEnvelope($normalizedScope, $nextVersion, $normalizedReason, $envelope['bump_counter'] + 1);

        return 'v' . $nextVersion;
    }

    public function store(): StoreInterface
    {
        return $this->store;
    }

    public function keyPrefix(): string
    {
        return $this->keyPrefix;
    }

    /**
     * Read the stored version envelope for the given scope, upgrading legacy
     * (ENVELOPE_VERSION=1) or empty entries to the current schema.
     *
     * @return array{version:int<1,max>,updated_at:string,envelope:int,bump_counter:int<0,max>,last_reason:string,last_bump_at:string}
     */
    public function readEnvelope(string $scope): array
    {
        $scope = $this->normalizeScope($scope);
        $key = $this->keyFor($scope);
        $raw = $this->store->get($key);

        if (! is_array($raw)) {
            return [
                'envelope' => self::ENVELOPE_VERSION,
                'scope' => $scope,
                'version' => 1,
                'updated_at' => '',
                'bump_counter' => 0,
                'last_reason' => '',
                'last_bump_at' => '',
            ];
        }

        $envelope = (int) ($raw['envelope'] ?? 0);

        if ($envelope !== self::ENVELOPE_VERSION) {
            $legacyVersion = isset($raw['version']) && is_int($raw['version']) && $raw['version'] > 0
                ? $raw['version']
                : 1;

            return [
                'envelope' => self::ENVELOPE_VERSION,
                'scope' => $scope,
                'version' => $legacyVersion,
                'updated_at' => is_string($raw['updated_at'] ?? null) ? (string) $raw['updated_at'] : '',
                'bump_counter' => max(0, $legacyVersion - 1),
                'last_reason' => '',
                'last_bump_at' => is_string($raw['updated_at'] ?? null) ? (string) $raw['updated_at'] : '',
            ];
        }

        $version = isset($raw['version']) && is_int($raw['version']) && $raw['version'] > 0 ? $raw['version'] : 1;
        $bumpCounter = isset($raw['bump_counter']) && is_int($raw['bump_counter']) && $raw['bump_counter'] >= 0
            ? $raw['bump_counter']
            : max(0, $version - 1);

        return [
            'envelope' => self::ENVELOPE_VERSION,
            'scope' => is_string($raw['scope'] ?? null) ? (string) $raw['scope'] : $scope,
            'version' => $version,
            'updated_at' => is_string($raw['updated_at'] ?? null) ? (string) $raw['updated_at'] : '',
            'bump_counter' => $bumpCounter,
            'last_reason' => is_string($raw['last_reason'] ?? null) ? (string) $raw['last_reason'] : '',
            'last_bump_at' => is_string($raw['last_bump_at'] ?? null) ? (string) $raw['last_bump_at'] : (is_string($raw['updated_at'] ?? null) ? (string) $raw['updated_at'] : ''),
        ];
    }

    private function bumpAtomically(string $scope, ?string $reason): int
    {
        assert($this->store instanceof AtomicIncrementableStoreInterface);

        $currentEnvelope = $this->readEnvelope($scope);
        $counterKey = $this->counterKeyFor($scope);
        $expectedNext = $currentEnvelope['version'] + 1;
        $initial = max(2, $expectedNext);
        $nextVersion = $this->store->incrementInt($counterKey, 1, $initial, $this->effectiveTtl());

        if ($nextVersion < $expectedNext) {
            $delta = $expectedNext - $nextVersion;
            $nextVersion = $this->store->incrementInt($counterKey, $delta, $initial, $this->effectiveTtl());
        }

        $timestamp = (new DateTimeImmutable())->format('c');
        $payload = [
            'envelope' => self::ENVELOPE_VERSION,
            'scope' => $scope,
            'version' => $nextVersion,
            'updated_at' => $timestamp,
            'bump_counter' => max(0, $nextVersion - 1),
            'last_reason' => (string) ($reason ?? ''),
            'last_bump_at' => $timestamp,
        ];

        $ttl = $this->effectiveTtl();
        $written = $ttl === null
            ? $this->store->forever($this->keyFor($scope), $payload)
            : $this->store->put($this->keyFor($scope), $payload, $ttl);

        if (! $written) {
            throw new \RuntimeException(sprintf(
                'Unable to persist cache version envelope for scope [%s].',
                $scope,
            ));
        }

        return $nextVersion;
    }

    private function writeEnvelope(string $scope, int $version, ?string $reason, int $bumpCounter): void
    {
        $key = $this->keyFor($scope);
        $timestamp = (new DateTimeImmutable())->format('c');
        $payload = [
            'envelope' => self::ENVELOPE_VERSION,
            'scope' => $scope,
            'version' => $version,
            'updated_at' => $timestamp,
            'bump_counter' => $bumpCounter,
            'last_reason' => (string) $reason,
            'last_bump_at' => $timestamp,
        ];

        $ttl = $this->effectiveTtl();

        if ($ttl === null) {
            $written = $this->store->forever($key, $payload);
        } else {
            $written = $this->store->put($key, $payload, $ttl);
        }

        if (! $written) {
            throw new \RuntimeException(sprintf(
                'Unable to persist cache version for scope [%s].',
                $scope,
            ));
        }
    }

    private function keyFor(string $scope): string
    {
        $suffix = sha1($scope);

        return $this->keyPrefix . '.' . substr($suffix, 0, 2) . '.' . $suffix;
    }

    private function counterKeyFor(string $scope): string
    {
        return $this->keyFor($scope) . '.ctr';
    }

    private function normalizeScope(string $scope): string
    {
        $normalized = trim($scope);

        return $normalized === '' ? 'global' : $normalized;
    }

    private function effectiveTtl(): DateInterval|int|null
    {
        if ($this->defaultTtl instanceof DateInterval) {
            return $this->defaultTtl;
        }

        if (is_int($this->defaultTtl) && $this->defaultTtl > 0) {
            return $this->defaultTtl;
        }

        return null;
    }
}

