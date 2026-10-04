<?php

declare(strict_types=1);

namespace Quantum\Cache;

use InvalidArgumentException;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Contracts\StoreInterface;
use VoltStack\Framework\Application;

final class CacheManager
{
    /**
     * @var array<string, Repository>
     */
    private array $stores = [];

    public function __construct(
        private readonly Application $app,
    ) {}

    public function store(?string $name = null): Repository
    {
        $name ??= (string) $this->config('default', 'file');

        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        return $this->stores[$name] = new Repository($this->resolveStore($name));
    }

    public function driver(?string $name = null): Repository
    {
        return $this->store($name);
    }

    public function pool(?string $name = null): Repository
    {
        return $this->store($name);
    }

    private function resolveStore(string $name): StoreInterface
    {
        $config = $this->storeConfig($name);
        $driver = (string) ($config['driver'] ?? 'file');
        $clock = $this->app->make(ClockInterface::class);

        return match ($driver) {
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

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->config('cache.' . $key, $default);
    }
}
