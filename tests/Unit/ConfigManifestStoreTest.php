<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Config\EnvReference;
use Quantum\Bootstrap\Config\SecretReference;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Publication\ConfigManifestStore;
use Quantum\Config\Publication\ConfigSnapshotCodec;
use VoltStack\Framework\Application;

final class ConfigManifestStoreTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-config-manifest-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

return [
    'name' => 'VoltStack Config Manifest',
    'env' => 'testing',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_application_registers_config_publication_services(): void
    {
        $app = new Application($this->basePath);

        self::assertInstanceOf(ConfigSnapshotCodec::class, $app->configSnapshotCodec());
        self::assertInstanceOf(ConfigManifestStore::class, $app->configManifestStore());
        self::assertSame(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $app->storagePath('framework/config')),
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $app->configManifestStore()->rootPath()),
        );
    }

    public function test_snapshot_codec_round_trips_references_and_config_id(): void
    {
        $codec = new ConfigSnapshotCodec();
        $repository = new ConfigRepository();
        $repository->set('app.name', 'VoltStack Config Manifest');
        $repository->set('app.env', new EnvReference('APP_ENV', 'testing'));
        $repository->set('app.key', new SecretReference('APP_KEY'));
        $repository->set('cache.default', 'array');

        $snapshot = $repository->snapshot(
            provenance: [
                'app' => [
                    'source' => 'config/app.php',
                ],
            ],
        );
        $configId = $codec->configId($snapshot);
        $encoded = $codec->encode($repository->snapshot(
            provenance: $snapshot->provenance(),
            configId: $configId,
        ));
        $decoded = $codec->decode($encoded);

        self::assertSame($configId, $encoded['config_id']);
        self::assertSame($configId, $decoded->configId());
        self::assertEquals($repository->all(), $decoded->all());
        self::assertSame($snapshot->provenance(), $decoded->provenance());
    }

    public function test_manifest_store_publishes_activates_and_rolls_back_snapshots(): void
    {
        $app = new Application($this->basePath);
        $repository = $app->make(ConfigRepository::class);
        $codec = $app->configSnapshotCodec();
        $store = $app->configManifestStore();

        $repository->set('app.env', new EnvReference('APP_ENV', 'testing'));
        $repository->set('app.key', new SecretReference('APP_KEY'));
        $repository->set('cache.default', 'array');

        $firstBaseSnapshot = $repository->snapshot(provenance: [
            'app' => 'config/app.php',
            'cache' => 'runtime',
        ]);
        $first = $store->publish($repository->snapshot(
            provenance: $firstBaseSnapshot->provenance(),
            configId: $codec->configId($firstBaseSnapshot),
        ));

        self::assertFileExists($first->manifestPath());
        self::assertSame($first->generationId(), $first->payload()['generation_id']);
        self::assertSame($first->configId(), $first->payload()['config_id']);

        $activatedFirst = $store->activateGeneration($first->generationId());

        self::assertSame($first->generationId(), $activatedFirst->id);

        $currentAfterFirst = $store->currentSnapshot();

        self::assertNotNull($currentAfterFirst);
        self::assertEquals($repository->all(), $currentAfterFirst->all());
        self::assertSame($first->configId(), $currentAfterFirst->configId());

        $repository->set('cache.default', 'redis');

        $secondBaseSnapshot = $repository->snapshot(provenance: [
            'app' => 'config/app.php',
            'cache' => 'runtime',
        ]);
        $second = $store->publish($repository->snapshot(
            provenance: $secondBaseSnapshot->provenance(),
            configId: $codec->configId($secondBaseSnapshot),
        ));
        $store->activateGeneration($second->generationId());

        $currentAfterSecond = $store->currentSnapshot();

        self::assertNotNull($currentAfterSecond);
        self::assertSame('redis', $currentAfterSecond->get('cache.default'));
        self::assertSame($second->configId(), $currentAfterSecond->configId());

        $rolled = $store->rollback();

        self::assertNotNull($rolled);
        self::assertSame($first->generationId(), $rolled->id);

        $currentAfterRollback = $store->currentSnapshot();

        self::assertNotNull($currentAfterRollback);
        self::assertEquals($firstBaseSnapshot->all(), $currentAfterRollback->all());
        self::assertSame($first->configId(), $currentAfterRollback->configId());
        self::assertSame('array', $currentAfterRollback->get('cache.default'));
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $subPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($subPath) || is_link($subPath)) {
                @unlink($subPath);
                continue;
            }

            $this->deleteDirectory($subPath);
        }

        @rmdir($path);
    }
}
