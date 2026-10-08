<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;
use Quantum\Authorization\Relationship\DatabaseRelationshipRepository;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class AuthorizationServiceProviderDatabaseRelationshipTest extends TestCase
{
    private string $basePath;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-authz-rel-provider-' . uniqid('', true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'relationships.sqlite';
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_defaults_include_memory_driver_and_database_table_configuration_for_relationships(): void
    {
        $app = new Application($this->basePath);
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);

        self::assertSame('memory', $config->get('authorization.relationships.driver'));
        self::assertSame(
            DatabaseRelationshipRepository::DEFAULT_RELATIONSHIPS_TABLE,
            $config->get('authorization.relationships.database.table'),
        );
        self::assertNull($config->get('authorization.relationships.database.connection'));
    }

    public function test_database_driver_resolves_database_relationship_repository(): void
    {
        $app = $this->makeApplication();
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.relationships.driver', 'database');

        $repository = $app->make(RelationshipRepositoryInterface::class);

        self::assertInstanceOf(DatabaseRelationshipRepository::class, $repository);
    }

    private function makeApplication(): Application
    {
        $app = new Application($this->basePath);
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'default');
        $config->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        return $app;
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
