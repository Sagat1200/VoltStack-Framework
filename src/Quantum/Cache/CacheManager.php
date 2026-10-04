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

        return $this->storeRepositories[$name] = new Repository(
            $this->resolveStore($name),
            marshaller: $this->app->make(MarshallerInterface::class),
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

        return $this->poolRepositories[$name] = new Repository(
            $this->resolveStore($storeName),
            $prefix,
            $defaultTtl,
            $this->app->make(MarshallerInterface::class),
            $this->app->make(VersionAuthorityInterface::class),
            $name,
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

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->config('cache.' . $key, $default);
    }
}
