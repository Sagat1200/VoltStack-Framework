<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\Config\BootstrapConfiguration;
use Quantum\Bootstrap\Config\EnvReference;
use Quantum\Bootstrap\Config\SecretReference;
use Quantum\Bootstrap\Manifest\BootstrapManifestStore;
use Quantum\Compilation\BuildManifest;

final class BootstrapManifestStoreTest extends TestCase
{
    private string $basePath;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-bootstrap-manifest-' . uniqid('', true);
        $this->storageRoot = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'bootstrap';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir($this->storageRoot, 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

return [
    'name' => 'VoltStack Bootstrap Manifest',
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

    public function test_bootstrap_configuration_serializes_references_without_materializing_secret_values(): void
    {
        $configuration = BootstrapConfiguration::fromSections(
            structural: [
                'cache' => ['driver' => 'php', 'compiled' => true],
            ],
            operational: [
                'worker' => ['mode' => 'single'],
                'app_env' => new EnvReference('APP_ENV', 'local'),
            ],
            secrets: [
                'app_key' => new SecretReference('APP_KEY', 'env'),
            ],
        );

        $payload = $configuration->toManifestPayload();

        self::assertSame('php', $payload['structural']['cache']['driver']);
        self::assertSame('env', $payload['operational']['app_env']['type']);
        self::assertSame('APP_ENV', $payload['operational']['app_env']['name']);
        self::assertSame('secret', $payload['secrets']['app_key']['type']);
        self::assertSame('APP_KEY', $payload['secrets']['app_key']['key']);
        self::assertArrayNotHasKey('value', $payload['secrets']['app_key'], 'Manifest payload must not embed secret values.');
        self::assertNotEmpty($configuration->fingerprint());
    }

    public function test_manifest_store_publishes_and_activates_bootstrap_generations(): void
    {
        $configuration = BootstrapConfiguration::fromSections(
            structural: ['runtime' => ['driver' => 'frankenphp']],
            operational: ['app_env' => new EnvReference('APP_ENV', 'testing')],
            secrets: ['app_key' => new SecretReference('APP_KEY')],
        );

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('console')
            ->withBootstrapConfiguration($configuration)
            ->build();

        $manifest = new BuildManifest($this->storageRoot);
        $store = new BootstrapManifestStore($manifest, $this->storageRoot);

        $artifact = $store->publish($plan);

        self::assertFileExists($artifact->manifestPath());
        self::assertSame($artifact->generationId(), $artifact->payload()['generation_id']);
        self::assertSame($artifact->fingerprint(), $artifact->payload()['fingerprint']);
        self::assertSame('frankenphp', $artifact->payload()['plan']['bootstrap_configuration']['structural']['runtime']['driver']);
        self::assertSame('secret', $artifact->payload()['plan']['bootstrap_configuration']['secrets']['app_key']['type']);

        $activated = $store->activateGeneration($artifact->generationId());
        self::assertSame($artifact->generationId(), $activated->id);

        $current = $store->currentManifest();
        self::assertNotNull($current);
        self::assertSame($artifact->generationId(), $current->generationId());
        self::assertSame($artifact->fingerprint(), $current->fingerprint());
    }

    public function test_manifest_store_rolls_back_to_previous_generation(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new BootstrapManifestStore($manifest, $this->storageRoot);

        $firstPlan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('console')
            ->withBootstrapConfiguration(BootstrapConfiguration::fromSections(
                structural: ['runtime' => ['driver' => 'frankenphp']],
            ))
            ->build();

        $first = $store->publish($firstPlan);
        $store->activateGeneration($first->generationId());

        $secondPlan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->withBootstrapConfiguration(BootstrapConfiguration::fromSections(
                structural: ['runtime' => ['driver' => 'roadrunner']],
            ))
            ->build();

        $second = $store->publish($secondPlan);
        $store->activateGeneration($second->generationId());

        $rolled = $store->rollback();

        self::assertNotNull($rolled);
        self::assertSame($first->generationId(), $rolled->id);

        $current = $store->currentManifest();
        self::assertNotNull($current);
        self::assertSame($first->generationId(), $current->generationId());
        self::assertSame('frankenphp', $current->payload()['plan']['bootstrap_configuration']['structural']['runtime']['driver']);
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
