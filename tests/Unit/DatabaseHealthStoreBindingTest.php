<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\Engine\DirectoryDatabaseHealthStore;
use Quantum\Database\Operation\Engine\DirectoryDatabaseIdempotencyStore;
use Quantum\Database\Operation\Engine\InMemoryDatabaseHealthStore;
use Quantum\Database\Operation\Engine\InMemoryDatabaseIdempotencyStore;
use Quantum\Database\Operation\Engine\JsonFileDatabaseHealthStore;
use Quantum\Database\Operation\Engine\JsonLineDatabaseHealthStore;
use Quantum\Database\Operation\Engine\NullDatabaseHealthStore;
use Quantum\Database\Operation\Engine\NullDatabaseIdempotencyStore;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseHealthStoreBindingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-health-binding-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_resolves_in_memory_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.health.store', 'in_memory');

        $store = $app->make(DatabaseHealthStoreInterface::class);

        self::assertInstanceOf(InMemoryDatabaseHealthStore::class, $store);
    }

    public function test_it_resolves_json_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.health.store', 'json');
        $app->make(ConfigRepository::class)->set('database.health.json_path', $this->basePath . DIRECTORY_SEPARATOR . 'database-health.json');

        $store = $app->make(DatabaseHealthStoreInterface::class);

        self::assertInstanceOf(JsonFileDatabaseHealthStore::class, $store);
    }

    public function test_it_resolves_jsonl_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.health.store', 'jsonl');
        $app->make(ConfigRepository::class)->set('database.health.jsonl_path', $this->basePath . DIRECTORY_SEPARATOR . 'database-health.jsonl');

        $store = $app->make(DatabaseHealthStoreInterface::class);

        self::assertInstanceOf(JsonLineDatabaseHealthStore::class, $store);
    }

    public function test_it_resolves_directory_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.health.store', 'directory');
        $app->make(ConfigRepository::class)->set('database.health.directory_path', $this->basePath . DIRECTORY_SEPARATOR . 'health-nodes');

        $store = $app->make(DatabaseHealthStoreInterface::class);

        self::assertInstanceOf(DirectoryDatabaseHealthStore::class, $store);
    }

    public function test_it_resolves_null_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.health.store', 'null');

        $store = $app->make(DatabaseHealthStoreInterface::class);

        self::assertInstanceOf(NullDatabaseHealthStore::class, $store);
    }

    public function test_it_resolves_in_memory_idempotency_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.idempotency.store', 'in_memory');

        $store = $app->make(DatabaseIdempotencyStoreInterface::class);

        self::assertInstanceOf(InMemoryDatabaseIdempotencyStore::class, $store);
    }

    public function test_it_resolves_directory_idempotency_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.idempotency.store', 'directory');
        $app->make(ConfigRepository::class)->set('database.idempotency.directory_path', $this->basePath . DIRECTORY_SEPARATOR . 'idempotency');

        $store = $app->make(DatabaseIdempotencyStoreInterface::class);

        self::assertInstanceOf(DirectoryDatabaseIdempotencyStore::class, $store);
    }

    public function test_it_resolves_null_idempotency_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.idempotency.store', 'null');

        $store = $app->make(DatabaseIdempotencyStoreInterface::class);

        self::assertInstanceOf(NullDatabaseIdempotencyStore::class, $store);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
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
