<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Query\Compiler\QueryCompilerInterface;
use VoltStack\Framework\Application;

final class DatabaseQueryCompilerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-query-compiler-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_compiles_select_insert_update_and_delete_queries(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite',
        ]);

        $builder = $app->make(DatabaseQueryManager::class)
            ->table('users')
            ->select('users.id', 'users.name')
            ->where('active', true)
            ->where('age', '>=', 18)
            ->orderBy('users.name')
            ->limit(10)
            ->offset(5);

        $select = $builder->compile();
        $compiler = $app->make(QueryCompilerInterface::class);
        $insert = $compiler->compile($app->make(DatabaseQueryManager::class)->table('users')->connection('default')->toSelectQuery());

        self::assertSame(
            'SELECT "users"."id", "users"."name" FROM "users" WHERE "active" = ? AND "age" >= ? ORDER BY "users"."name" ASC LIMIT 10 OFFSET 5',
            $select->command->sql,
        );
        self::assertSame([1, 18], $select->bindings->normalized());
        self::assertSame('select', $select->command->metadata['query_type'] ?? null);
        self::assertSame('SELECT * FROM "users"', $insert->command->sql);
    }

    public function test_builder_terminals_compile_dml_queries_with_expected_bindings(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite',
        ]);

        $compiler = $app->make(QueryCompilerInterface::class);
        $manager = $app->make(DatabaseQueryManager::class);

        $insert = $compiler->compile(
            new \Quantum\Database\Query\Model\InsertQuery(
                into: new \Quantum\Database\Query\Model\TableReference('users'),
                values: ['name' => 'VoltStack', 'active' => true],
            ),
        );
        $update = $compiler->compile(
            new \Quantum\Database\Query\Model\UpdateQuery(
                table: new \Quantum\Database\Query\Model\TableReference('users'),
                values: ['name' => 'Quantum'],
                predicates: [new \Quantum\Database\Query\Model\Predicate('active', '=', true)],
            ),
        );
        $delete = $compiler->compile(
            new \Quantum\Database\Query\Model\DeleteQuery(
                from: new \Quantum\Database\Query\Model\TableReference('users'),
                predicates: [new \Quantum\Database\Query\Model\Predicate('active', '=', false)],
            ),
        );

        self::assertSame('INSERT INTO "users" ("name", "active") VALUES (?, ?)', $insert->command->sql);
        self::assertSame(['VoltStack', 1], $insert->bindings->normalized());
        self::assertSame('UPDATE "users" SET "name" = ? WHERE "active" = ?', $update->command->sql);
        self::assertSame(['Quantum', 1], $update->bindings->normalized());
        self::assertSame('DELETE FROM "users" WHERE "active" = ?', $delete->command->sql);
        self::assertSame([0], $delete->bindings->normalized());
        self::assertInstanceOf(DatabaseQueryManager::class, $manager);
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
