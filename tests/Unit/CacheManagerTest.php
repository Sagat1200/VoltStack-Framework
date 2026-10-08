<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Cache\CachePoolDoctor;
use Quantum\Cache\CachePoolSummary;
use Quantum\Cache\CacheManager;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Repository;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class CacheManagerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-cache-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_uses_the_file_store_and_persists_values(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('cache.stores.file.path', $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'data');
        $app->make(ConfigRepository::class)->set('cache.compiled.pages', $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'pages');

        $manager = $app->make(CacheManager::class);
        $store = $manager->store();

        self::assertInstanceOf(Repository::class, $store);
        self::assertFalse($store->has('views.home'));

        $store->put('views.home', ['compiled' => true]);

        self::assertTrue($store->has('views.home'));
        self::assertSame(['compiled' => true], $store->get('views.home'));
        self::assertSame(['compiled' => true], cache('views.home'));

        $store->forget('views.home');

        self::assertFalse($store->has('views.home'));
    }

    public function test_it_supports_memory_and_null_drivers(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('cache.stores.memory', ['driver' => 'memory']);
        $config->set('cache.stores.null', ['driver' => 'null']);
        $config->set('cache.pools.runtime', [
            'store' => 'memory',
            'prefix' => 'runtime',
            'default_ttl' => 60,
        ]);

        $manager = $app->make(CacheManager::class);

        $memory = $manager->store('memory');
        self::assertTrue($memory->put('runtime.counter', 1));
        self::assertTrue($memory->has('runtime.counter'));
        self::assertSame(1, $memory->get('runtime.counter'));
        self::assertTrue($manager->pool('runtime')->put('counter', 5));
        self::assertSame(5, $manager->pool('runtime')->get('counter'));
        self::assertSame('missing', $memory->get('counter', 'missing'));

        $null = $manager->store('null');
        self::assertTrue($null->put('runtime.counter', 99));
        self::assertFalse($null->has('runtime.counter'));
        self::assertSame('fallback', $null->get('runtime.counter', 'fallback'));
    }

    public function test_it_supports_logical_pools_with_prefix_and_default_ttl(): void
    {
        $app = new Application($this->basePath);
        $clock = $this->clockAt(1_700_000_000);
        $app->instance(ClockInterface::class, $clock);

        $config = $app->make(ConfigRepository::class);
        $config->set('cache.stores.memory', ['driver' => 'memory']);
        $config->set('cache.pools.alpha', [
            'store' => 'memory',
            'prefix' => 'alpha',
            'default_ttl' => 10,
        ]);
        $config->set('cache.pools.beta', [
            'store' => 'memory',
            'prefix' => 'beta',
            'default_ttl' => 10,
        ]);
        $config->set('cache.default_pool', 'alpha');

        $manager = $app->make(CacheManager::class);
        $alpha = $manager->pool('alpha');
        $beta = $manager->pool('beta');

        self::assertTrue($alpha->put('shared', 'alpha-value'));
        self::assertTrue($beta->put('shared', 'beta-value'));

        self::assertSame('alpha-value', $alpha->get('shared'));
        self::assertSame('beta-value', $beta->get('shared'));
        self::assertSame('alpha-value', $manager->pool()->get('shared'));

        $clock->advanceSeconds(11);

        self::assertFalse($alpha->has('shared'));
        self::assertFalse($beta->has('shared'));
    }

    public function test_pool_clear_invalidates_only_the_current_pool_generation(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('cache.stores.memory', ['driver' => 'memory']);
        $config->set('cache.pools.alpha', [
            'store' => 'memory',
            'prefix' => 'alpha',
            'default_ttl' => 60,
        ]);
        $config->set('cache.pools.beta', [
            'store' => 'memory',
            'prefix' => 'beta',
            'default_ttl' => 60,
        ]);

        $manager = $app->make(CacheManager::class);
        $alpha = $manager->pool('alpha');
        $beta = $manager->pool('beta');

        self::assertTrue($alpha->put('dashboard', 'alpha-v1'));
        self::assertTrue($beta->put('dashboard', 'beta-v1'));
        self::assertSame('alpha-v1', $alpha->get('dashboard'));
        self::assertSame('beta-v1', $beta->get('dashboard'));

        self::assertTrue($alpha->clear());

        self::assertSame('missing', $alpha->get('dashboard', 'missing'));
        self::assertSame('beta-v1', $beta->get('dashboard'));

        self::assertTrue($alpha->put('dashboard', 'alpha-v2'));
        self::assertSame('alpha-v2', $alpha->get('dashboard'));
        self::assertSame('beta-v1', $beta->get('dashboard'));
    }

    public function test_tagged_pool_clear_invalidates_only_the_tag_namespace(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('cache.stores.memory', ['driver' => 'memory']);
        $config->set('cache.pools.catalog', [
            'store' => 'memory',
            'prefix' => 'catalog',
            'default_ttl' => 60,
        ]);

        $manager = $app->make(CacheManager::class);
        $catalog = $manager->pool('catalog');
        $featured = $catalog->tags(['featured']);
        $seasonal = $catalog->tags(['seasonal']);

        self::assertTrue($featured->put('homepage', 'featured-v1'));
        self::assertTrue($seasonal->put('homepage', 'seasonal-v1'));
        self::assertTrue($catalog->put('homepage', 'base-v1'));

        self::assertSame('featured-v1', $featured->get('homepage'));
        self::assertSame('seasonal-v1', $seasonal->get('homepage'));
        self::assertSame('base-v1', $catalog->get('homepage'));

        self::assertTrue($featured->clear());

        self::assertSame('missing', $featured->get('homepage', 'missing'));
        self::assertSame('seasonal-v1', $seasonal->get('homepage'));
        self::assertSame('base-v1', $catalog->get('homepage'));
    }

    public function test_it_lists_configured_pools_with_driver_topology_and_classification(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('cache.stores', [
            'memory' => ['driver' => 'memory'],
            'null' => ['driver' => 'null'],
        ]);
        $config->set('cache.pools', [
            'alpha' => [
                'store' => 'memory',
                'prefix' => 'alpha',
                'default_ttl' => 60,
            ],
            'disabled' => [
                'store' => 'null',
                'prefix' => 'disabled',
                'default_ttl' => null,
            ],
        ]);

        $pools = $app->make(CacheManager::class)->listPools();

        self::assertContainsOnlyInstancesOf(CachePoolSummary::class, $pools);
        self::assertCount(2, $pools);
        self::assertSame('alpha', $pools[0]->pool);
        self::assertSame('memory', $pools[0]->driver);
        self::assertSame('single_store', $pools[0]->topology);
        self::assertSame('local', $pools[0]->classification);
        self::assertSame('disabled', $pools[1]->pool);
        self::assertSame('discard', $pools[1]->classification);
    }

    public function test_it_reports_local_pool_doctor_payload_with_stable_fields(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('cache.stores', [
            'memory' => ['driver' => 'memory'],
            'null' => ['driver' => 'null'],
        ]);
        $config->set('cache.pools', [
            'runtime' => [
                'store' => 'memory',
                'prefix' => 'runtime',
                'default_ttl' => 60,
            ],
            'disabled' => [
                'store' => 'null',
                'prefix' => 'disabled',
                'default_ttl' => null,
            ],
        ]);
        $config->set('cache.default_pool', 'runtime');

        $doctor = $app->make(CacheManager::class)->doctor('runtime');
        $payload = $doctor->toArray();

        self::assertInstanceOf(CachePoolDoctor::class, $doctor);
        self::assertSame('cache.pool.doctor.v1', $payload['schema_version'] ?? null);
        self::assertSame(16, strlen((string) ($payload['operation_id'] ?? '')));
        self::assertSame('runtime', $payload['pool'] ?? null);
        self::assertSame(40, strlen((string) ($payload['scope_fingerprint'] ?? '')));
        self::assertSame('available', $payload['outcome'] ?? null);
        self::assertSame(['pool_config_loaded', 'store_resolved', 'repository_diagnostics'], $payload['effects'] ?? null);
        self::assertSame([], $payload['warnings'] ?? null);
        self::assertSame('memory', $payload['configuration']['driver'] ?? null);
        self::assertSame('runtime', $payload['configuration']['prefix'] ?? null);
        self::assertSame('local', $payload['configuration']['classification'] ?? null);
        self::assertSame('runtime', $payload['diagnostics']['context']['key_prefix'] ?? null);
        self::assertSame($payload['scope_fingerprint'] ?? null, $payload['diagnostics']['context_fingerprint'] ?? null);
        self::assertSame('v1:runtime', $payload['diagnostics']['storage_namespace'] ?? null);
        self::assertSame($payload, $doctor->jsonSerialize());
    }

    public function test_it_reports_warnings_for_discard_pools_without_default_ttl(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('cache.stores', [
            'null' => ['driver' => 'null'],
        ]);
        $config->set('cache.pools', [
            'disabled' => [
                'store' => 'null',
                'prefix' => 'disabled',
                'default_ttl' => null,
            ],
        ]);
        $config->set('cache.default_pool', 'disabled');

        $payload = $app->make(CacheManager::class)->doctor()->toArray();

        self::assertSame('disabled', $payload['pool'] ?? null);
        self::assertSame(['discard_store', 'default_ttl_unset'], $payload['warnings'] ?? null);
        self::assertSame('discard', $payload['configuration']['classification'] ?? null);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }

    private function clockAt(int $unixSeconds): object
    {
        return new class($unixSeconds) implements ClockInterface {
            public function __construct(
                private int $unixSeconds,
            ) {}

            public function nowUnixSeconds(): int
            {
                return $this->unixSeconds;
            }

            public function nowUnixMilliseconds(): int
            {
                return $this->unixSeconds * 1000;
            }

            public function monotonicNanoseconds(): int
            {
                return $this->unixSeconds * 1_000_000_000;
            }

            public function advanceSeconds(int $seconds): void
            {
                $this->unixSeconds += $seconds;
            }
        };
    }
}
