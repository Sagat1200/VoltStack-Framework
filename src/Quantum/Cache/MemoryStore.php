<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeInterface;
use Quantum\Cache\Concerns\InteractsWithTime;
use Quantum\Cache\Contracts\AtomicIncrementableStoreInterface;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Contracts\InspectableStoreInterface;

final class MemoryStore implements InspectableStoreInterface, AtomicIncrementableStoreInterface
{
    use InteractsWithTime;

    /**
     * @var array<string, array{expires_at: int|null, value: mixed, created_at_ms: int}>
     */
    private array $items = [];

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
        ];

        return true;
    }

    public function forever(string $key, mixed $value): bool
    {
        $this->items[$key] = [
            'expires_at' => null,
            'value' => $value,
            'created_at_ms' => $this->nowUnixMilliseconds(),
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
        ];

        return $next;
    }

    /**
     * @return array{expires_at: int|null, value: mixed, created_at_ms: int}|null
     */
    public function payload(string $key): ?array
    {
        return $this->read($key);
    }

    /**
     * @return array{expires_at: int|null, value: mixed, created_at_ms: int}|null
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
