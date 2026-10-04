<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeInterface;
use Quantum\Cache\Contracts\StoreInterface;

final class NullStore implements StoreInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        return true;
    }

    public function forever(string $key, mixed $value): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function forget(string $key): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }
}
