<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Config\DatabaseConfiguration;
use Quantum\Database\Connection\Connection;
use Quantum\Database\Connection\ConnectionFactory;
use Quantum\Database\Connection\ConnectionDefinitionRegistry;
use Quantum\Database\Connection\ConnectionManager;
use Quantum\Database\Contracts\ConnectionInterface;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Dialect\DialectResolver;
use Quantum\Database\Driver\DriverRegistry;
use Quantum\Database\Platform\PlatformResolver;
use Quantum\Database\Runtime\DatabaseExecutionScope;
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

    public function test_disconnect_all_owned_only_disconnects_connections_that_match_the_target_scope(): void
    {
        $scope = new DatabaseExecutionScope(
            'scope-a',
            'request-a',
            microtime(true),
            new DatabaseConfiguration(),
        );
        $manager = new ConnectionManager(
            new ConnectionDefinitionRegistry(new DatabaseConfiguration()),
            new ConnectionFactory(new DriverRegistry(), new PlatformResolver(), new DialectResolver()),
            $scope,
        );

        $owned = $this->createMock(ConnectionInterface::class);
        $owned->expects(self::once())->method('disconnect');
        $owned->method('isConnected')->willReturn(true);

        $legacy = $this->createMock(ConnectionInterface::class);
        $legacy->expects(self::never())->method('disconnect');
        $legacy->method('isConnected')->willReturn(true);

        $foreign = $this->createMock(ConnectionInterface::class);
        $foreign->expects(self::never())->method('disconnect');
        $foreign->method('isConnected')->willReturn(true);

        $reflection = new \ReflectionClass($manager);
        $connections = $reflection->getProperty('connections');
        $connections->setAccessible(true);
        $connections->setValue($manager, [
            'owned' => $owned,
            'legacy' => $legacy,
            'foreign' => $foreign,
        ]);

        $leases = $reflection->getProperty('leases');
        $leases->setAccessible(true);
        $leases->setValue($manager, [
            'owned' => [
                'name' => 'owned',
                'scope_id' => 'scope-a',
                'runtime_request_id' => 'request-a',
                'opened_at' => microtime(true),
            ],
            'legacy' => [
                'name' => 'legacy',
                'scope_id' => null,
                'runtime_request_id' => null,
                'opened_at' => microtime(true),
            ],
            'foreign' => [
                'name' => 'foreign',
                'scope_id' => 'scope-b',
                'runtime_request_id' => 'request-b',
                'opened_at' => microtime(true),
            ],
        ]);

        $report = $manager->disconnectAllOwned('scope-a');

        self::assertSame(1, $report['disconnected']);
        self::assertSame(['legacy', 'foreign'], $report['skipped']);
        self::assertSame([], $report['errors']);
        self::assertSame(['legacy', 'foreign'], array_keys($connections->getValue($manager)));
        self::assertSame(['legacy', 'foreign'], array_keys($leases->getValue($manager)));
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
