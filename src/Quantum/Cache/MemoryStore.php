<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeInterface;
use Quantum\Cache\Concerns\InteractsWithTime;
use Quantum\Cache\Contracts\AtomicIncrementableStoreInterface;
use Quantum\Cache\Contracts\CasStoreInterface;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Contracts\InspectableStoreInterface;

final class MemoryStore implements InspectableStoreInterface, AtomicIncrementableStoreInterface, CasStoreInterface
{
    use InteractsWithTime;

    /**
     * @var array<string, array{expires_at: int|null, value: mixed, created_at_ms: int, incarnation: int}>
     */
    private array $items = [];

    /**
     * Monotonic write sequence used to defeat ABA: every mutation advances
     * this counter, so a delete+recreate always produces a distinct token
     * even when the value and timestamp coincide.
     */
    private int $writeSequence = 0;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->read($key);

        if ($payload === null) {
            return $default;
        }

        return $payload['value'];
    }

    public function put(string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $expiresAt = $this->expirationTimestamp($ttl);

        if ($expiresAt !== null && $expiresAt <= $this->nowUnixSeconds()) {
            return $this->forget($key);
        }

        $this->items[$key] = [
            'expires_at' => $expiresAt,
            'value' => $value,
            'created_at_ms' => $this->nowUnixMilliseconds(),
            'incarnation' => ++$this->writeSequence,
        ];

        return true;
    }

    public function forever(string $key, mixed $value): bool
    {
        $this->items[$key] = [
            'expires_at' => null,
            'value' => $value,
            'created_at_ms' => $this->nowUnixMilliseconds(),
            'incarnation' => ++$this->writeSequence,
        ];

        return true;
    }

    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    public function forget(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->items = [];

        return true;
    }

    public function sourceLevel(): string
    {
        return 'memory';
    }

    public function incrementInt(
        string $key,
        int $step = 1,
        int $initial = 1,
        DateInterval|DateTimeInterface|int|null $ttl = null,
    ): int {
        if ($step < 1) {
            throw new \InvalidArgumentException('Increment step must be a positive integer.');
        }

        if ($initial < 0) {
            throw new \InvalidArgumentException('Increment initial value must be a non-negative integer.');
        }

        $existing = $this->read($key);

        if ($existing === null) {
            $next = $initial;
            $expiresAt = $this->expirationTimestamp($ttl);

            if ($expiresAt !== null && $expiresAt <= $this->nowUnixSeconds()) {
                $this->forget($key);

                return $initial;
            }

            $this->items[$key] = [
                'expires_at' => $expiresAt,
                'value' => $next,
                'created_at_ms' => $this->nowUnixMilliseconds(),
                'incarnation' => ++$this->writeSequence,
            ];

            return $next;
        }

        $current = $existing['value'];

        if (! is_int($current)) {
            throw new \RuntimeException(sprintf(
                'Cannot increment non-integer cache value at key [%s].',
                $key,
            ));
        }

        $next = $current + $step;
        $expiresAt = $ttl === null ? $existing['expires_at'] : $this->expirationTimestamp($ttl);

        if ($expiresAt !== null && $expiresAt <= $this->nowUnixSeconds()) {
            $this->forget($key);

            return $initial;
        }

        $this->items[$key] = [
            'expires_at' => $expiresAt,
            'value' => $next,
            'created_at_ms' => $existing['created_at_ms'],
            'incarnation' => ++$this->writeSequence,
        ];

        return $next;
    }

    /**
     * @return array{expires_at: int|null, value: mixed, created_at_ms: int, incarnation: int}|null
     */
    public function payload(string $key): ?array
    {
        return $this->read($key);
    }

    public function snapshot(string $key, mixed $default = null): ?CasSnapshot
    {
        $payload = $this->read($key);

        if ($payload === null) {
            return null;
        }

        $incarnation = (int) ($payload['incarnation'] ?? 0);

        return new CasSnapshot(
            key: $key,
            value: $payload['value'],
            token: $this->tokenFor($payload['value'], $incarnation, (int) $payload['created_at_ms']),
            expiresAt: $payload['expires_at'],
            incarnation: $incarnation,
        );
    }

    public function compareAndSwap(
        string $key,
        string $expectedToken,
        mixed $value,
        DateInterval|DateTimeInterface|int|null $ttl = null,
    ): CasResult {
        $expiresAt = $this->expirationTimestamp($ttl);

        if ($expiresAt !== null && $expiresAt <= $this->nowUnixSeconds()) {
            return new CasResult(
                state: CasResult::REJECTED,
                key: $key,
                value: $value,
                token: '',
                reason: 'ttl_in_the_past',
            );
        }

        $current = $this->read($key);

        if ($current === null) {
            return new CasResult(
                state: CasResult::REJECTED,
                key: $key,
                value: $value,
                token: '',
                reason: 'key_absent',
            );
        }

        $incarnation = (int) ($current['incarnation'] ?? 0);
        $currentToken = $this->tokenFor($current['value'], $incarnation, (int) $current['created_at_ms']);

        if (! hash_equals($currentToken, $expectedToken)) {
            return new CasResult(
                state: CasResult::REJECTED,
                key: $key,
                value: $current['value'],
                token: $currentToken,
                reason: 'token_mismatch',
                incarnation: $incarnation,
            );
        }

        $nextIncarnation = ++$this->writeSequence;
        $newToken = $this->tokenFor($value, $nextIncarnation, (int) $current['created_at_ms']);

        $this->items[$key] = [
            'expires_at' => $expiresAt,
            'value' => $value,
            'created_at_ms' => (int) $current['created_at_ms'],
            'incarnation' => $nextIncarnation,
        ];

        return new CasResult(
            state: CasResult::APPLIED,
            key: $key,
            value: $value,
            token: $newToken,
            incarnation: $nextIncarnation,
        );
    }

    private function tokenFor(mixed $value, int $incarnation, int $createdAtMs): string
    {
        return sha1($incarnation . ':' . $createdAtMs . ':' . sha1(serialize($value)));
    }

    /**
     * @return array{expires_at: int|null, value: mixed, created_at_ms: int, incarnation: int}|null
     */
    private function read(string $key): ?array
    {
        if (! array_key_exists($key, $this->items)) {
            return null;
        }

        $payload = $this->items[$key];
        $expiresAt = $payload['expires_at'];

        if (is_int($expiresAt) && $expiresAt <= $this->nowUnixSeconds()) {
            unset($this->items[$key]);

            return null;
        }

        return $payload;
    }
}
