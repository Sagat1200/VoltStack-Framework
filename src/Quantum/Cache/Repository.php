<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Quantum\Cache\Contracts\ClockInterface;
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
        private readonly ?ClockInterface $clock = null,
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
        $effectiveTtl = $ttl ?? $this->defaultTtl;
        $normalizedKey = $this->normalizeKey($key);
        $result = $this->put($key, $value, $ttl);
        $metadata = $this->metadataFor($normalizedKey);

        return $this->receiptFor(
            operation: 'put',
            result: $result,
            writeId: $metadata?->writeId,
            details: $this->operationDetails($key, [
                'ttl' => $this->describeTtl($effectiveTtl),
                'expired_immediately' => $result && $metadata === null && $this->isImmediateExpiration($effectiveTtl),
            ]),
        );
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->store->forever($this->normalizeKey($key), $this->encodeStoredValue($value));
    }

    public function foreverReceipt(string $key, mixed $value): WriteReceipt
    {
        $normalizedKey = $this->normalizeKey($key);
        $result = $this->forever($key, $value);
        $metadata = $this->metadataFor($normalizedKey);

        return $this->receiptFor(
            operation: 'forever',
            result: $result,
            writeId: $metadata?->writeId,
            details: $this->operationDetails($key, [
                'ttl' => ['mode' => 'forever'],
            ]),
        );
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
        return $this->receiptFor(
            operation: 'forget',
            result: $this->forget($key),
            details: $this->operationDetails($key),
        );
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
        return $this->receiptFor(
            operation: 'clear',
            result: $this->clear(),
            details: $this->operationDetails(null, [
                'mode' => $this->tagNames !== []
                    ? 'tag_invalidation'
                    : $this->clearStrategy(),
            ]),
        );
    }

    public function context(): CacheContext
    {
        return CacheContext::from(
            keyPrefix: $this->keyPrefix,
            versionScope: $this->versionScope,
            tags: $this->tagNames,
            defaultTtl: $this->defaultTtl,
        );
    }

    public function diagnostics(): RepositoryDiagnostics
    {
        return new RepositoryDiagnostics(
            sourceLevel: $this->store instanceof InspectableStoreInterface ? $this->store->sourceLevel() : 'store',
            context: $this->context(),
            capabilities: $this->capabilities(),
            versions: $this->versionMetadata(),
            store: $this->storeDiagnostics(),
            observedAtMs: $this->cacheClock()->nowUnixMilliseconds(),
            clearStrategy: $this->clearStrategy(),
        );
    }

    public function withContext(CacheContext $context): self
    {
        $current = $this->context();
        $context = CacheContext::from(
            keyPrefix: $context->keyPrefix,
            versionScope: $context->versionScope,
            tags: $context->tags,
            defaultTtl: $context->defaultTtl,
        );

        return new self(
            $this->store,
            $this->composePrefix($current->keyPrefix, $context->keyPrefix),
            $context->defaultTtl ?? $this->defaultTtl,
            $this->marshaller,
            $this->versionAuthority,
            $context->versionScope ?? $this->versionScope,
            $this->mergeTags($context->tags),
            $this->clock,
        );
    }

    public function namespace(array|string|null $prefix): self
    {
        return $this->withContext(CacheContext::from(keyPrefix: $prefix));
    }

    public function tags(array|string $tags): self
    {
        return $this->withContext(CacheContext::from(tags: $tags));
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
        return $this->receiptFor(
            operation: 'invalidate_tags',
            result: $this->invalidateTags($tags),
            details: $this->operationDetails(null, [
                'tags' => $this->normalizeTags($tags),
            ]),
        );
    }

    public function invalidateDependencies(array|string $dependencies): bool
    {
        return $this->invalidateTags($dependencies);
    }

    public function invalidateDependenciesReceipt(array|string $dependencies): WriteReceipt
    {
        return $this->receiptFor(
            operation: 'invalidate_dependencies',
            result: $this->invalidateDependencies($dependencies),
            details: $this->operationDetails(null, [
                'dependencies' => $this->normalizeTags($dependencies),
            ]),
        );
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

    /**
     * @param array<string, mixed> $details
     */
    private function receiptFor(string $operation, bool $result, ?string $writeId = null, array $details = []): WriteReceipt
    {
        return new WriteReceipt(
            operationId: bin2hex(random_bytes(8)),
            effect: $result ? Effect::Applied : Effect::Rejected,
            writeId: $writeId,
            details: ['operation' => $operation, ...$details],
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
        $observedAtMs = $this->cacheClock()->nowUnixMilliseconds();
        $freshUntilMs = is_int($expiresAt) ? $expiresAt * 1000 : null;

        return new EntryMetadata(
            writeId: sha1($key . ':' . $payload['created_at_ms']),
            createdAtMs: $payload['created_at_ms'],
            freshUntilMs: $freshUntilMs,
            hardUntilMs: $freshUntilMs,
            observedAtMs: $observedAtMs,
            ageMs: max(0, $observedAtMs - $payload['created_at_ms']),
            ttlRemainingMs: $freshUntilMs === null ? null : max(0, $freshUntilMs - $observedAtMs),
            versions: $this->versionMetadata(),
            sourceLevel: $this->store->sourceLevel(),
        );
    }

    private function normalizeKey(string $key): string
    {
        $prefix = CacheContext::normalizePrefix($this->keyPrefix);

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
        $merged = array_merge(CacheContext::normalizeTags($this->tagNames), $this->normalizeTags($tags));
        $merged = array_values(array_unique($merged));
        sort($merged, SORT_STRING);

        return $merged;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTags(array|string $tags): array
    {
        return CacheContext::normalizeTags($tags);
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

    private function cacheClock(): ClockInterface
    {
        return $this->clock ?? new SystemClock();
    }

    private function isImmediateExpiration(DateInterval|DateTimeInterface|int|null $ttl): bool
    {
        $expiresAt = $this->expirationTimestamp($ttl);

        return $expiresAt !== null && $expiresAt <= $this->cacheClock()->nowUnixSeconds();
    }

    private function expirationTimestamp(DateInterval|DateTimeInterface|int|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable('@' . $this->cacheClock()->nowUnixSeconds()))->add($ttl)->getTimestamp();
        }

        if ($ttl instanceof DateTimeInterface) {
            return $ttl->getTimestamp();
        }

        return $this->cacheClock()->nowUnixSeconds() + $ttl;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeTtl(DateInterval|DateTimeInterface|int|null $ttl): array
    {
        if ($ttl === null) {
            return ['mode' => 'forever'];
        }

        $expiresAt = $this->expirationTimestamp($ttl);
        $remainingSeconds = $expiresAt === null ? null : $expiresAt - $this->cacheClock()->nowUnixSeconds();

        return [
            'mode' => $ttl instanceof DateTimeInterface ? 'absolute' : 'relative',
            'expires_at' => $expiresAt,
            'remaining_seconds' => $remainingSeconds,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function operationDetails(?string $key, array $extra = []): array
    {
        $details = [
            'source' => $this->store instanceof InspectableStoreInterface ? $this->store->sourceLevel() : 'store',
            'scope' => $this->versionScope,
            'tags' => $this->tagNames,
            'context' => $this->context()->toArray(),
            'diagnostics' => $this->diagnosticsSnapshot(),
        ];

        if ($key !== null) {
            $details['key'] = $key;
            $details['normalized_key'] = $this->normalizeKey($key);
        }

        return [...$details, ...$extra];
    }

    private function composePrefix(string $base, string $extra): string
    {
        return CacheContext::normalizePrefix([$base, $extra]);
    }

    /**
     * @return array<string, bool>
     */
    private function capabilities(): array
    {
        return [
            'inspectable' => $this->store instanceof InspectableStoreInterface,
            'scoped_versions' => $this->versionAuthority !== null && $this->versionScope !== null && trim($this->versionScope) !== '',
            'tag_versions' => $this->versionAuthority !== null,
            'contextualized' => $this->context()->keyPrefix !== '' || $this->context()->versionScope !== null || $this->context()->tags !== [],
            'ttl_default' => $this->defaultTtl !== null,
            'marshaller' => $this->marshaller !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeDiagnostics(): array
    {
        return [
            'class' => $this->store::class,
            'inspectable' => $this->store instanceof InspectableStoreInterface,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnosticsSnapshot(): array
    {
        return [
            'source_level' => $this->store instanceof InspectableStoreInterface ? $this->store->sourceLevel() : 'store',
            'context' => $this->context()->toArray(),
            'capabilities' => $this->capabilities(),
            'versions' => $this->versionMetadata(),
            'store' => $this->storeDiagnostics(),
            'observed_at_ms' => $this->cacheClock()->nowUnixMilliseconds(),
            'clear_strategy' => $this->clearStrategy(),
        ];
    }

    private function clearStrategy(): string
    {
        if ($this->tagNames !== []) {
            return 'tag_invalidation';
        }

        if ($this->versionScope !== null && trim($this->versionScope) !== '') {
            return 'namespace_rotation';
        }

        return 'store_flush';
    }
}
