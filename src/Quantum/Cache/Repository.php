<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeInterface;
use Quantum\Cache\Contracts\InspectableStoreInterface;
use Quantum\Cache\Contracts\MarshallerInterface;
use Quantum\Cache\Contracts\StoreInterface;
use Quantum\Cache\Contracts\VersionAuthorityInterface;

final class Repository
{
    private const ENVELOPE_MARKER = '__quantum_cache_envelope_v1';

    public function __construct(
        private readonly StoreInterface $store,
        private readonly string $keyPrefix = '',
        private readonly DateInterval|DateTimeInterface|int|null $defaultTtl = null,
        private readonly ?MarshallerInterface $marshaller = null,
        private readonly ?VersionAuthorityInterface $versionAuthority = null,
        private readonly ?string $versionScope = null,
        private readonly array $tagNames = [],
    ) {}

    public function lookup(string $key): Lookup
    {
        $normalizedKey = $this->normalizeKey($key);

        if (! $this->store->has($normalizedKey)) {
            return new Lookup(HitState::Miss, null, missReason: 'not_found');
        }

        $value = $this->decodeStoredValue($this->store->get($normalizedKey));

        return new Lookup(
            HitState::Fresh,
            $value,
            $this->metadataFor($normalizedKey),
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $normalizedKey = $this->normalizeKey($key);

        if (! $this->store->has($normalizedKey)) {
            return $default;
        }

        return $this->decodeStoredValue($this->store->get($normalizedKey));
    }

    public function put(string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        return $this->store->put(
            $this->normalizeKey($key),
            $this->encodeStoredValue($value),
            $ttl ?? $this->defaultTtl,
        );
    }

    public function putReceipt(string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): WriteReceipt
    {
        return $this->receiptFor($this->put($key, $value, $ttl));
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->store->forever($this->normalizeKey($key), $this->encodeStoredValue($value));
    }

    public function foreverReceipt(string $key, mixed $value): WriteReceipt
    {
        return $this->receiptFor($this->forever($key, $value));
    }

    public function has(string $key): bool
    {
        return $this->store->has($this->normalizeKey($key));
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($this->normalizeKey($key));
    }

    public function forgetReceipt(string $key): WriteReceipt
    {
        return $this->receiptFor($this->forget($key));
    }

    public function flush(): bool
    {
        return $this->store->flush();
    }

    public function clear(): bool
    {
        if ($this->tagNames !== []) {
            return $this->invalidateTags($this->tagNames);
        }

        if ($this->versionAuthority === null || $this->versionScope === null || trim($this->versionScope) === '') {
            return $this->flush();
        }

        $this->versionAuthority->bump($this->versionScope);

        return true;
    }

    public function clearReceipt(): WriteReceipt
    {
        return $this->receiptFor($this->clear());
    }

    public function tags(array|string $tags): self
    {
        return new self(
            $this->store,
            $this->keyPrefix,
            $this->defaultTtl,
            $this->marshaller,
            $this->versionAuthority,
            $this->versionScope,
            $this->mergeTags($tags),
        );
    }

    public function dependencies(array|string $dependencies): self
    {
        return $this->tags($dependencies);
    }

    public function invalidateTags(array|string $tags): bool
    {
        if ($this->versionAuthority === null) {
            return false;
        }

        foreach ($this->tagScopesFor($tags) as $scope) {
            $this->versionAuthority->bump($scope);
        }

        return true;
    }

    public function invalidateTagsReceipt(array|string $tags): WriteReceipt
    {
        return $this->receiptFor($this->invalidateTags($tags));
    }

    public function invalidateDependencies(array|string $dependencies): bool
    {
        return $this->invalidateTags($dependencies);
    }

    public function invalidateDependenciesReceipt(array|string $dependencies): WriteReceipt
    {
        return $this->invalidateTagsReceipt($dependencies);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /**
     * @param iterable<string> $keys
     * @return array<string, mixed>
     */
    public function many(iterable $keys, mixed $default = null): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
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
        if (! $this->store instanceof InspectableStoreInterface) {
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
            versions: $this->versionMetadata(),
            sourceLevel: $this->store->sourceLevel(),
        );
    }

    private function normalizeKey(string $key): string
    {
        $prefix = trim($this->keyPrefix);

        if ($prefix === '') {
            return $this->withTagVersionPrefix($this->withVersionPrefix($key));
        }

        return $this->withTagVersionPrefix($this->withVersionPrefix($prefix . ':' . $key));
    }

    private function encodeStoredValue(mixed $value): mixed
    {
        $marshaller = $this->marshaller ?? new PhpSerializeMarshaller();
        $encoded = $marshaller->encode($value);

        return [
            self::ENVELOPE_MARKER => true,
            'format' => $encoded->formatId,
            'schema' => $encoded->schemaVersion,
            'bytes' => $encoded->bytes,
        ];
    }

    private function decodeStoredValue(mixed $value): mixed
    {
        if (! is_array($value) || ($value[self::ENVELOPE_MARKER] ?? false) !== true) {
            return $value;
        }

        $bytes = $value['bytes'] ?? null;
        $format = $value['format'] ?? null;
        $schema = $value['schema'] ?? null;

        if (! is_string($bytes) || ! is_string($format) || ! is_int($schema)) {
            return $value;
        }

        $marshaller = $this->marshaller ?? new PhpSerializeMarshaller();

        return $marshaller->decode(new EncodedValue(
            formatId: $format,
            schemaVersion: $schema,
            bytes: $bytes,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function versionMetadata(): array
    {
        $versions = [];

        if ($this->versionAuthority !== null && $this->versionScope !== null && trim($this->versionScope) !== '') {
            $versions['namespace'] = $this->versionAuthority->currentVersion($this->versionScope);
        }

        foreach ($this->tagNames as $tag) {
            $versions['tag:' . $tag] = $this->tagVersionFor($tag);
        }

        return $versions;
    }

    private function withVersionPrefix(string $key): string
    {
        if ($this->versionAuthority === null || $this->versionScope === null || trim($this->versionScope) === '') {
            return $key;
        }

        return $this->versionAuthority->currentVersion($this->versionScope) . ':' . $key;
    }

    private function withTagVersionPrefix(string $key): string
    {
        if ($this->tagNames === [] || $this->versionAuthority === null) {
            return $key;
        }

        $prefix = implode(':', array_map(
            fn(string $tag): string => 'tag[' . $tag . '=' . $this->tagVersionFor($tag) . ']',
            $this->tagNames,
        ));

        return $prefix . ':' . $key;
    }

    /**
     * @return array<int, string>
     */
    private function mergeTags(array|string $tags): array
    {
        $merged = array_merge($this->tagNames, $this->normalizeTags($tags));
        $merged = array_values(array_unique($merged));
        sort($merged, SORT_STRING);

        return $merged;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTags(array|string $tags): array
    {
        $values = is_array($tags) ? $tags : [$tags];
        $normalized = [];

        foreach ($values as $tag) {
            $name = trim((string) $tag);

            if ($name === '') {
                continue;
            }

            $normalized[] = $name;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function tagScopesFor(array|string $tags): array
    {
        return array_map(
            fn(string $tag): string => $this->tagScopeFor($tag),
            $this->normalizeTags($tags),
        );
    }

    private function tagScopeFor(string $tag): string
    {
        $scope = $this->versionScope !== null && trim($this->versionScope) !== ''
            ? $this->versionScope
            : 'global';

        return $scope . '.tag.' . $tag;
    }

    private function tagVersionFor(string $tag): string
    {
        if ($this->versionAuthority === null) {
            return 'v1';
        }

        return $this->versionAuthority->currentVersion($this->tagScopeFor($tag));
    }
}
