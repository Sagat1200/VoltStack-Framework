<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Connection\Connection;
use Quantum\Database\Connection\ConnectionDefinitionRegistry;
use Quantum\Database\Connection\ConnectionManager;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use VoltStack\Framework\Application;

final class DatabaseConnectionManagerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-connection-manager-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_resolves_the_default_connection_manager_and_compiled_definitions(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'analytics');
        $config->set('database.connections.analytics', [
            'driver' => 'sqlite',
            'database' => $this->basePath . DIRECTORY_SEPARATOR . 'analytics.sqlite',
        ]);

        $manager = $app->make(ConnectionManagerInterface::class);
        $registry = $app->make(ConnectionDefinitionRegistry::class);
        $connection = $manager->connection();

        self::assertInstanceOf(ConnectionManager::class, $manager);
        self::assertTrue($manager->has('analytics'));
        self::assertSame('analytics', $manager->defaultConnectionName());
        self::assertTrue($registry->has('analytics'));
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame('analytics', $connection->name());
        self::assertSame('sqlite', $connection->platform()->id());
        self::assertSame('sqlite', $connection->dialect()->id());
        self::assertSame($connection, $manager->connection('analytics'));
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
