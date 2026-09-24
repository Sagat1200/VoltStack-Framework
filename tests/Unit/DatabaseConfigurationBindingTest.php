<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Config\DatabaseConfiguration;
use Quantum\Database\Config\FrameworkDatabaseConfigurationProvider;
use Quantum\Database\Contracts\DatabaseConfigurationProviderInterface;
use Quantum\Database\Integration\DatabaseCompositionRoot;
use Quantum\Database\Integration\DatabaseServiceProvider;
use VoltStack\Framework\Application;

final class DatabaseConfigurationBindingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-config-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_registers_the_database_service_provider_by_default(): void
    {
        $app = new Application($this->basePath);

        self::assertArrayHasKey(DatabaseServiceProvider::class, $app->getProviders());
    }

    public function test_it_resolves_a_typed_database_configuration_from_framework_config(): void
    {
        $app = new Application($this->basePath);

        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'analytics');
        $config->set('database.connections.analytics', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'analytics',
        ]);
        $config->set('database.runtime', [
            'strict_scope' => true,
        ]);
        $config->set('database.telemetry', [
            'enabled' => true,
        ]);

        $provider = $app->make(DatabaseConfigurationProviderInterface::class);
        $configuration = $app->make(DatabaseConfiguration::class);
        $compositionRoot = $app->make(DatabaseCompositionRoot::class);

        self::assertInstanceOf(FrameworkDatabaseConfigurationProvider::class, $provider);
        self::assertInstanceOf(DatabaseCompositionRoot::class, $compositionRoot);
        self::assertSame('analytics', $configuration->defaultConnectionName);
        self::assertSame(['analytics'], $configuration->connectionNames());
        self::assertTrue($configuration->hasConnection('analytics'));
        self::assertSame('pgsql', $configuration->connection('analytics')['driver'] ?? null);
        self::assertTrue($configuration->runtimeOption('strict_scope', false));
        self::assertTrue($configuration->telemetryOption('enabled', false));
        self::assertSame($configuration, $compositionRoot->configuration());
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
}
