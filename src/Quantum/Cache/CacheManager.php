<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Contracts\MarshallerInterface;
use Quantum\Cache\Contracts\StoreInterface;
use Quantum\Cache\Contracts\VersionAuthorityInterface;
use VoltStack\Framework\Application;

final class CacheManager
{
    /**
     * @var array<string, Repository>
     */
    private array $storeRepositories = [];

    /**
     * @var array<string, Repository>
     */
    private array $poolRepositories = [];

    /**
     * @var array<string, StoreInterface>
     */
    private array $resolvedStores = [];

    public function __construct(
        private readonly Application $app,
    ) {}

    public function store(?string $name = null): Repository
    {
        $name ??= (string) $this->config('default', 'file');

        if (isset($this->storeRepositories[$name])) {
            return $this->storeRepositories[$name];
        }

        $clock = $this->app->make(ClockInterface::class);

        return $this->storeRepositories[$name] = new Repository(
            $this->resolveStore($name),
            marshaller: $this->app->make(MarshallerInterface::class),
            clock: $clock,
        );
    }

    public function driver(?string $name = null): Repository
    {
        return $this->store($name);
    }

    public function pool(?string $name = null): Repository
    {
        $name ??= (string) $this->config('default_pool', $this->config('default', 'file'));

        if (isset($this->poolRepositories[$name])) {
            return $this->poolRepositories[$name];
        }

        $config = $this->poolConfig($name);
        $storeName = (string) ($config['store'] ?? $this->config('default', 'file'));
        $prefix = (string) ($config['prefix'] ?? $name);
        $defaultTtl = $this->normalizePoolDefaultTtl($config['default_ttl'] ?? null);
        $clock = $this->app->make(ClockInterface::class);

        return $this->poolRepositories[$name] = new Repository(
            $this->resolveStore($storeName),
            $prefix,
            $defaultTtl,
            $this->app->make(MarshallerInterface::class),
            $this->app->make(VersionAuthorityInterface::class),
            $name,
            clock: $clock,
        );
    }

    /**
     * @return array<int, CachePoolSummary>
     */
    public function listPools(): array
    {
        $pools = $this->config('pools', []);

        if (! is_array($pools)) {
            return [];
        }

        $names = array_keys(array_filter($pools, static fn(mixed $config): bool => is_array($config)));
        sort($names, SORT_STRING);

        return array_map(fn(string $name): CachePoolSummary => $this->poolSummary($name), $names);
    }

    public function doctor(?string $name = null): CachePoolDoctor
    {
        $name ??= (string) $this->config('default_pool', $this->config('default', 'file'));

        $summary = $this->poolSummary($name);
        $diagnostics = $this->pool($name)->diagnostics()->toArray();

        return new CachePoolDoctor(
            schemaVersion: 'cache.pool.doctor.v1',
            operationId: bin2hex(random_bytes(8)),
            pool: $name,
            scopeFingerprint: (string) ($diagnostics['context_fingerprint'] ?? ''),
            outcome: 'available',
            effects: [
                'pool_config_loaded',
                'store_resolved',
                'repository_diagnostics',
            ],
            warnings: $this->doctorWarnings($summary),
            configuration: $summary->toArray(),
            diagnostics: $diagnostics,
        );
    }

    public function planClear(?string $name = null): CacheInvalidationPlan
    {
        $name ??= (string) $this->config('default_pool', $this->config('default', 'file'));
        $repository = $this->pool($name);
        $diagnostics = $repository->diagnostics()->toArray();

        return new CacheInvalidationPlan(
            schemaVersion: 'cache.invalidation.plan.v1',
            operationId: bin2hex(random_bytes(8)),
            pool: $name,
            action: 'clear',
            dryRun: true,
            applySupported: true,
            scopeFingerprint: (string) ($diagnostics['context_fingerprint'] ?? ''),
            outcome: 'planned',
            effects: [
                'scope_resolved',
                'namespace_rotation_planned',
            ],
            warnings: $this->clearPlanWarnings($diagnostics),
            target: [
                'mode' => 'clear',
                'clear_strategy' => $diagnostics['clear_strategy'] ?? 'store_flush',
                'context' => $diagnostics['context'] ?? [],
                'storage_namespace' => $diagnostics['storage_namespace'] ?? '',
            ],
            diagnostics: $diagnostics,
        );
    }

    public function planInvalidateTags(string $name, array|string $tags): CacheInvalidationPlan
    {
        $repository = $this->pool($name)->tags($tags);
        $diagnostics = $repository->diagnostics()->toArray();
        $normalizedTags = CacheContext::normalizeTags($tags);

        return new CacheInvalidationPlan(
            schemaVersion: 'cache.invalidation.plan.v1',
            operationId: bin2hex(random_bytes(8)),
            pool: $name,
            action: 'invalidate_tags',
            dryRun: true,
            applySupported: true,
            scopeFingerprint: (string) ($diagnostics['context_fingerprint'] ?? ''),
            outcome: 'planned',
            effects: [
                'scope_resolved',
                'tag_invalidation_planned',
            ],
            warnings: $normalizedTags === [] ? ['empty_tag_selection'] : [],
            target: [
                'mode' => 'invalidate_tags',
                'tags' => $normalizedTags,
                'clear_strategy' => $diagnostics['clear_strategy'] ?? 'tag_invalidation',
                'invalidation_scopes' => $diagnostics['invalidation_scopes']['tags'] ?? [],
                'storage_namespace' => $diagnostics['storage_namespace'] ?? '',
            ],
            diagnostics: $diagnostics,
        );
    }

    private function resolveStore(string $name): StoreInterface
    {
        if (isset($this->resolvedStores[$name])) {
            return $this->resolvedStores[$name];
        }

        $config = $this->storeConfig($name);
        $driver = (string) ($config['driver'] ?? 'file');
        $clock = $this->app->make(ClockInterface::class);

        return $this->resolvedStores[$name] = match ($driver) {
            'file' => new FileStore(
                (string) ($config['path'] ?? $this->app->cachePath('data')),
                (string) ($config['prefix'] ?? $this->config('prefix', 'voltstack')),
                $clock,
            ),
            'memory' => new MemoryStore($clock),
            'null' => new NullStore(),
            default => throw new InvalidArgumentException(sprintf('Cache driver [%s] is not supported.', $driver)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function storeConfig(string $name): array
    {
        $stores = $this->config('stores', []);

        if (! is_array($stores) || ! isset($stores[$name]) || ! is_array($stores[$name])) {
            if ($name === 'file') {
                return [
                    'driver' => 'file',
                    'path' => $this->app->cachePath('data'),
                    'prefix' => $this->config('prefix', 'voltstack'),
                ];
            }

            throw new InvalidArgumentException(sprintf('Cache store [%s] is not configured.', $name));
        }

        return $stores[$name];
    }

    /**
     * @return array<string, mixed>
     */
    private function poolConfig(string $name): array
    {
        $pools = $this->config('pools', []);

        if (! is_array($pools) || ! isset($pools[$name]) || ! is_array($pools[$name])) {
            if ($name === 'default') {
                return [
                    'store' => $this->config('default', 'file'),
                    'prefix' => 'default',
                    'default_ttl' => null,
                ];
            }

            throw new InvalidArgumentException(sprintf('Cache pool [%s] is not configured.', $name));
        }

        return $pools[$name];
    }

    private function normalizePoolDefaultTtl(mixed $ttl): DateInterval|DateTimeInterface|int|null
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval || $ttl instanceof DateTimeInterface) {
            return $ttl;
        }

        if (is_int($ttl)) {
            return $ttl;
        }

        if (is_numeric($ttl)) {
            return (int) $ttl;
        }

        throw new InvalidArgumentException('Cache pool default_ttl must be null, int, DateInterval or DateTimeInterface.');
    }

    private function poolSummary(string $name): CachePoolSummary
    {
        $config = $this->poolConfig($name);
        $storeName = (string) ($config['store'] ?? $this->config('default', 'file'));
        $storeConfig = $this->storeConfig($storeName);
        $driver = (string) ($storeConfig['driver'] ?? 'file');

        return new CachePoolSummary(
            pool: $name,
            store: $storeName,
            driver: $driver,
            prefix: (string) ($config['prefix'] ?? $name),
            defaultTtl: $config['default_ttl'] ?? null,
            topology: 'single_store',
            classification: $this->classificationForDriver($driver),
        );
    }

    /**
     * @return array<int, string>
     */
    private function doctorWarnings(CachePoolSummary $summary): array
    {
        $warnings = [];

        if ($summary->driver === 'null') {
            $warnings[] = 'discard_store';
        }

        if ($summary->defaultTtl === null) {
            $warnings[] = 'default_ttl_unset';
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $diagnostics
     * @return array<int, string>
     */
    private function clearPlanWarnings(array $diagnostics): array
    {
        $warnings = [];

        if (($diagnostics['clear_strategy'] ?? null) === 'store_flush') {
            $warnings[] = 'physical_flush_fallback';
        }

        return $warnings;
    }

    private function classificationForDriver(string $driver): string
    {
        return match ($driver) {
            'memory' => 'local',
            'null' => 'discard',
            default => 'persistent_local',
        };
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->config('cache.' . $key, $default);
    }
}
