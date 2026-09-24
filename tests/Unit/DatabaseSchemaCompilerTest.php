<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Schema\Builder\TableBlueprint;
use Quantum\Database\Schema\Compiler\SchemaCompiler;
use Quantum\Database\Schema\Model\DropTableDefinition;
use VoltStack\Framework\Application;

final class DatabaseSchemaCompilerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-schema-compiler-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_compiles_create_and_drop_table_for_sqlite(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite',
        ]);

        $blueprint = new TableBlueprint('users', true);
        $blueprint->id();
        $blueprint->string('name');
        $blueprint->boolean('active')->default(true);
        $blueprint->timestamps();

        $compiler = $app->make(SchemaCompiler::class);
        $create = $compiler->compileCreateTable($blueprint->toCreateDefinition());
        $drop = $compiler->compileDropTable(new DropTableDefinition('users', true));

        self::assertCount(1, $create->commands);
        self::assertSame(
            'CREATE TABLE IF NOT EXISTS "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" TEXT NOT NULL, "active" INTEGER NOT NULL DEFAULT 1, "created_at" TEXT, "updated_at" TEXT)',
            $create->commands[0]->sql,
        );
        self::assertSame('create_table', $create->commands[0]->metadata['schema_operation'] ?? null);
        self::assertSame('DROP TABLE IF EXISTS "users"', $drop->commands[0]->sql);
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
