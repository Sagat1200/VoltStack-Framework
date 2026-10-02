<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\QueryExecutorInterface;
use Quantum\Database\Execution\CompiledDatabaseCommand;
use Quantum\Database\Execution\DatabaseResultType;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

final class DatabaseQueryBuilderExecutionTest extends TestCase
{
    private string $basePath;
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-query-builder-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_query_builder_executes_select_insert_update_and_delete_against_sqlite(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        $router = $app->make(Router::class);
        $router->get('/database/query-builder', DatabaseQueryBuilderExecutionController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/database/query-builder'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $payload['inserted']);
        self::assertSame(['Quantum', 'VoltStack'], $payload['names']);
        self::assertSame('Quantum', $payload['first_name']);
        self::assertSame(1, $payload['updated']);
        self::assertSame(1, $payload['deleted']);
        self::assertSame(1, $payload['remaining']);
        self::assertSame('SELECT "name" FROM "builder_items" WHERE "active" = ? ORDER BY "name" ASC', $payload['compiled_sql']);
        self::assertSame([1], $payload['compiled_bindings']);
    }

    public function test_query_builder_executes_inner_and_left_joins_against_sqlite(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        $router = $app->make(Router::class);
        $router->get('/database/query-builder-joins', DatabaseQueryBuilderJoinExecutionController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/database/query-builder-joins'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            ['user' => 'Grace', 'post' => 'Cobol', 'category' => null],
            ['user' => 'Ada', 'post' => 'Compilers', 'category' => 'Engineering'],
        ], $payload['joined_rows']);
        self::assertSame(2, $payload['joined_count']);
        self::assertSame(
            'SELECT "u"."name" AS "user_name", "p"."title" AS "post_title", "c"."name" AS "category_name" FROM "builder_users" AS "u" INNER JOIN "builder_posts" AS "p" ON "p"."user_id" = "u"."id" LEFT JOIN "builder_categories" AS "c" ON "c"."id" = "p"."category_id" WHERE "u"."active" = ? ORDER BY "p"."title" ASC',
            $payload['compiled_sql'],
        );
        self::assertSame([1], $payload['compiled_bindings']);
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

final class DatabaseQueryBuilderExecutionController
{
    public function __construct(
        private readonly QueryExecutorInterface $queries,
        private readonly DatabaseQueryManager $db,
    ) {
    }

    public function __invoke(): array
    {
        $this->queries->execute(new CompiledDatabaseCommand(
            sql: 'CREATE TABLE IF NOT EXISTS builder_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, active INTEGER NOT NULL)',
            resultType: DatabaseResultType::NoResult,
        ));
        $this->queries->execute(new CompiledDatabaseCommand(
            sql: 'DELETE FROM builder_items',
            resultType: DatabaseResultType::AffectedRows,
        ));

        $insertA = $this->db->table('builder_items')->insert([
            'name' => 'VoltStack',
            'active' => true,
        ]);
        $insertB = $this->db->table('builder_items')->insert([
            'name' => 'Quantum',
            'active' => true,
        ]);

        $builder = $this->db->table('builder_items')
            ->select('name')
            ->where('active', true)
            ->orderBy('name');

        $rows = $builder->get();
        $first = $builder->first();
        $compiled = $builder->compile();
        $updated = $this->db->table('builder_items')
            ->where('name', 'VoltStack')
            ->update(['active' => false]);
        $deleted = $this->db->table('builder_items')
            ->where('name', 'VoltStack')
            ->delete();
        $remaining = $this->db->table('builder_items')
            ->select('id')
            ->where('active', true)
            ->get();

        return [
            'inserted' => $insertA->affectedRows + $insertB->affectedRows,
            'names' => array_values(array_map(fn(array $row): string => (string) $row['name'], $rows->rows())),
            'first_name' => $first['name'] ?? null,
            'updated' => $updated->affectedRows,
            'deleted' => $deleted->affectedRows,
            'remaining' => count($remaining->rows()),
            'compiled_sql' => $compiled->command->sql,
            'compiled_bindings' => array_values($compiled->bindings->normalized()),
        ];
    }
}

final class DatabaseQueryBuilderJoinExecutionController
{
    public function __construct(
        private readonly QueryExecutorInterface $queries,
        private readonly DatabaseQueryManager $db,
    ) {
    }

    public function __invoke(): array
    {
        $this->queries->execute(new CompiledDatabaseCommand(
            sql: 'CREATE TABLE IF NOT EXISTS builder_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, active INTEGER NOT NULL)',
            resultType: DatabaseResultType::NoResult,
        ));
        $this->queries->execute(new CompiledDatabaseCommand(
            sql: 'CREATE TABLE IF NOT EXISTS builder_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
            resultType: DatabaseResultType::NoResult,
        ));
        $this->queries->execute(new CompiledDatabaseCommand(
            sql: 'CREATE TABLE IF NOT EXISTS builder_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, category_id INTEGER NULL, title TEXT NOT NULL)',
            resultType: DatabaseResultType::NoResult,
        ));
        $this->queries->execute(new CompiledDatabaseCommand(
            sql: 'DELETE FROM builder_posts',
            resultType: DatabaseResultType::AffectedRows,
        ));
        $this->queries->execute(new CompiledDatabaseCommand(
            sql: 'DELETE FROM builder_categories',
            resultType: DatabaseResultType::AffectedRows,
        ));
        $this->queries->execute(new CompiledDatabaseCommand(
            sql: 'DELETE FROM builder_users',
            resultType: DatabaseResultType::AffectedRows,
        ));

        $this->db->table('builder_users')->insert(['name' => 'Ada', 'active' => true]);
        $this->db->table('builder_users')->insert(['name' => 'Grace', 'active' => true]);
        $this->db->table('builder_users')->insert(['name' => 'Inactive', 'active' => false]);

        $this->db->table('builder_categories')->insert(['name' => 'Engineering']);

        $this->db->table('builder_posts')->insert(['user_id' => 1, 'category_id' => 1, 'title' => 'Compilers']);
        $this->db->table('builder_posts')->insert(['user_id' => 2, 'category_id' => null, 'title' => 'Cobol']);
        $this->db->table('builder_posts')->insert(['user_id' => 3, 'category_id' => 1, 'title' => 'Hidden']);

        $builder = $this->db->table('builder_users')
            ->as('u')
            ->select('u.name AS user_name', 'p.title AS post_title', 'c.name AS category_name')
            ->join('builder_posts', 'p.user_id', 'u.id', alias: 'p')
            ->leftJoin('builder_categories', 'c.id', 'p.category_id', alias: 'c')
            ->where('u.active', true)
            ->orderBy('p.title');

        $rows = $builder->get()->rows();
        $compiled = $builder->compile();

        return [
            'joined_rows' => array_map(
                static fn(array $row): array => [
                    'user' => $row['user_name'] ?? null,
                    'post' => $row['post_title'] ?? null,
                    'category' => $row['category_name'] ?? null,
                ],
                $rows,
            ),
            'joined_count' => count($rows),
            'compiled_sql' => $compiled->command->sql,
            'compiled_bindings' => array_values($compiled->bindings->normalized()),
        ];
    }
}
