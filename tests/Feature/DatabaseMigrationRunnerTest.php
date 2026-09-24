<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Migration\MigrationRepository;
use Quantum\Database\Migration\MigrationRunner;
use Quantum\Database\Schema\SchemaManager;
use VoltStack\Framework\Application;

final class DatabaseMigrationRunnerTest extends TestCase
{
    private string $basePath;
    private string $databasePath;
    private string $migrationsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-migrations-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'database', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations', 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
        $this->migrationsPath = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_schema_manager_can_create_and_drop_tables(): void
    {
        $app = $this->makeApp();
        $schema = $app->make(SchemaManager::class);

        $schema->create('schema_items', function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
        }, true);

        self::assertTrue($schema->hasTable('schema_items'));

        $schema->dropIfExists('schema_items');

        self::assertFalse($schema->hasTable('schema_items'));
        $app->make(ConnectionManagerInterface::class)->disconnectAll();
    }

    public function test_migration_runner_applies_and_rolls_back_discovered_migrations(): void
    {
        $this->writeMigration('2026_09_23_000001_create_posts.php', <<<'PHP'
<?php

return new class implements \Quantum\Database\Migration\MigrationInterface {
    public function up(\Quantum\Database\Schema\SchemaManager $schema): void
    {
        $schema->create('posts', function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('title');
            $table->boolean('published')->default(false);
        }, true);
    }

    public function down(\Quantum\Database\Schema\SchemaManager $schema): void
    {
        $schema->dropIfExists('posts');
    }
};
PHP
);
        $this->writeMigration('2026_09_23_000002_create_comments.php', <<<'PHP'
<?php

return new class implements \Quantum\Database\Migration\MigrationInterface {
    public function up(\Quantum\Database\Schema\SchemaManager $schema): void
    {
        $schema->create('comments', function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('body');
            $table->integer('post_id');
        }, true);
    }

    public function down(\Quantum\Database\Schema\SchemaManager $schema): void
    {
        $schema->dropIfExists('comments');
    }
};
PHP
);

        $app = $this->makeApp();
        $runner = $app->make(MigrationRunner::class);
        $schema = $app->make(SchemaManager::class);
        $repository = $app->make(MigrationRepository::class);

        $applied = $runner->migrate();

        self::assertSame(2, $applied);
        self::assertTrue($schema->hasTable('quantum_migrations'));
        self::assertTrue($schema->hasTable('posts'));
        self::assertTrue($schema->hasTable('comments'));
        self::assertSame(2, $repository->count());
        self::assertSame([
            '2026_09_23_000001_create_posts',
            '2026_09_23_000002_create_comments',
        ], $repository->appliedNames());

        $rolledBack = $runner->rollbackLastBatch();

        self::assertSame(2, $rolledBack);
        self::assertFalse($schema->hasTable('posts'));
        self::assertFalse($schema->hasTable('comments'));
        self::assertSame(0, $repository->count());
        self::assertSame([], $repository->appliedNames());
        $app->make(ConnectionManagerInterface::class)->disconnectAll();
    }

    private function makeApp(): Application
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        return $app;
    }

    private function writeMigration(string $name, string $contents): void
    {
        file_put_contents($this->migrationsPath . DIRECTORY_SEPARATOR . $name, $contents);
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
