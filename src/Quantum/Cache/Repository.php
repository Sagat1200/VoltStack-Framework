<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeInterface;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Contracts\StoreInterface;

final class Repository
{
    public function __construct(
        private readonly StoreInterface $store,
        private readonly ?ClockInterface $clock = null,
    ) {}

    public function lookup(string $key): Lookup
    {
        if (! $this->store->has($key)) {
            return new Lookup(HitState::Miss, null, missReason: 'not_found');
        }

        $value = $this->store->get($key);

        return new Lookup(
            HitState::Fresh,
            $value,
            $this->metadataFor($key),
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($key, $default);
    }

    public function put(string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        return $this->store->put($key, $value, $ttl);
    }

    public function putReceipt(string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): WriteReceipt
    {
        return $this->receiptFor($this->put($key, $value, $ttl));
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->store->forever($key, $value);
    }

    public function foreverReceipt(string $key, mixed $value): WriteReceipt
    {
        return $this->receiptFor($this->forever($key, $value));
    }

    public function has(string $key): bool
    {
        return $this->store->has($key);
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($key);
    }

    public function forgetReceipt(string $key): WriteReceipt
    {
        return $this->receiptFor($this->forget($key));
    }

    public function flush(): bool
    {
        return $this->store->flush();
    }

    public function remember(string $key, DateInterval|DateTimeInterface|int|null $ttl, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->forever($key, $value);

        return $value;
    }

    private function receiptFor(bool $result): WriteReceipt
    {
        return new WriteReceipt(
            operationId: bin2hex(random_bytes(8)),
            effect: $result ? Effect::Applied : Effect::Rejected,
        );
    }

    private function metadataFor(string $key): ?EntryMetadata
    {
        if (! $this->store instanceof MemoryStore) {
            return null;
        }

        $payload = $this->store->payload($key);

        if ($payload === null) {
            return null;
        }

        $expiresAt = $payload['expires_at'];

        return new EntryMetadata(
            writeId: sha1($key . ':' . $payload['created_at_ms']),
            createdAtMs: $payload['created_at_ms'],
            freshUntilMs: is_int($expiresAt) ? $expiresAt * 1000 : null,
            hardUntilMs: is_int($expiresAt) ? $expiresAt * 1000 : null,
            sourceLevel: 'memory',
        );
    }
}
