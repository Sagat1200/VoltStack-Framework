<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Loading\PhpConfigLoader;

final class PhpConfigLoaderTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-config-loader-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_php_config_loader_builds_items_documents_and_provenance(): void
    {
        $this->writeConfig('app', [
            'name' => 'VoltStack',
            'env' => 'testing',
        ]);
        $this->writeConfig('cache', [
            'driver' => 'filesystem',
            'ttl' => 300,
        ]);

        $loader = new PhpConfigLoader();
        $loaded = $loader->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');

        self::assertSame('VoltStack', $loaded->items()['app']['name']);
        self::assertSame('filesystem', $loaded->items()['cache']['driver']);
        self::assertCount(2, $loaded->documents());
        self::assertSame(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            $loaded->provenance()['app'],
        );
        self::assertSame(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cache.php',
            $loaded->provenance()['cache'],
        );
    }

    public function test_config_repository_load_path_preserves_read_api_and_captures_metadata(): void
    {
        $this->writeConfig('app', [
            'name' => 'VoltStack',
            'env' => 'testing',
        ]);

        $repository = new ConfigRepository();
        $repository->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');

        self::assertSame('VoltStack', $repository->get('app.name'));
        self::assertTrue($repository->has('app.env'));
        self::assertCount(1, $repository->documents());
        self::assertSame(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            $repository->provenance()['app'],
        );
        self::assertSame(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            $repository->snapshot()->provenance()['app'],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeConfig(string $name, array $payload): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $name . '.php',
            "<?php\n\nreturn " . var_export($payload, true) . ";\n",
        );
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
