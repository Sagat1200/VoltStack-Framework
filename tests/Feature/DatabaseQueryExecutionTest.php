<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\QueryExecutorInterface;
use Quantum\Database\Execution\CompiledDatabaseCommand;
use Quantum\Database\Execution\DatabaseResultType;
use Quantum\Database\Execution\ExecutionContext;
use Quantum\Database\Execution\ExecutionException;
use Quantum\Database\Execution\RuntimeBindingSet;
use Quantum\Database\Runtime\DatabaseContext;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

final class DatabaseQueryExecutionTest extends TestCase
{
    private string $basePath;
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-execution-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_query_executor_executes_sqlite_commands_and_returns_normalized_results(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        $router = $app->make(Router::class);
        $router->get('/database/execution', DatabaseQueryExecutionController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/database/execution'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('default', $payload['default_connection']);
        self::assertSame('rows', $payload['rows_type']);
        self::assertSame('scalar', $payload['scalar_type']);
        self::assertSame('affected_rows', $payload['update_type']);
        self::assertSame(1, $payload['total']);
        self::assertSame('VoltStack', $payload['first_name']);
        self::assertSame(1, $payload['updated_rows']);
        self::assertTrue($payload['same_executor_instance']);
    }

    public function test_query_executor_normalizes_execution_failures(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        $router = $app->make(Router::class);
        $router->get('/database/execution-error', DatabaseQueryExecutionErrorController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/database/execution-error'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('statement_execution', $payload['category']);
        self::assertSame('statement_execution', $payload['phase']);
        self::assertSame('Database execution failed during [statement_execution].', $payload['message']);
        self::assertSame('SELECT * FROM missing_table', $payload['sql']);
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

final class DatabaseQueryExecutionController
{
    public function __construct(
        private readonly QueryExecutorInterface $queries,
        private readonly DatabaseContext $database,
    ) {
    }

    public function __invoke(QueryExecutorInterface $queries): array
    {
        $context = new ExecutionContext(correlationId: 'feature-dv-db-003');

        $queries->execute(new CompiledDatabaseCommand(
            sql: 'CREATE TABLE IF NOT EXISTS execution_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, active INTEGER NOT NULL)',
            resultType: DatabaseResultType::NoResult,
        ));

        $queries->execute(new CompiledDatabaseCommand(
            sql: 'DELETE FROM execution_items',
            resultType: DatabaseResultType::AffectedRows,
        ));

        $queries->execute(
            new CompiledDatabaseCommand(
                sql: 'INSERT INTO execution_items (name, active) VALUES (:name, :active)',
                resultType: DatabaseResultType::AffectedRows,
            ),
            new RuntimeBindingSet([
                ':name' => 'VoltStack',
                ':active' => true,
            ]),
            $context,
        );

        $rows = $this->queries->execute(
            new CompiledDatabaseCommand(
                sql: 'SELECT id, name, active FROM execution_items WHERE active = :active ORDER BY id ASC',
                resultType: DatabaseResultType::Rows,
            ),
            new RuntimeBindingSet([':active' => true]),
            $context,
        );

        $scalar = $this->queries->execute(
            new CompiledDatabaseCommand(
                sql: 'SELECT COUNT(*) AS aggregate FROM execution_items WHERE active = :active',
                resultType: DatabaseResultType::Scalar,
            ),
            new RuntimeBindingSet([':active' => true]),
            $context,
        );

        $update = $this->queries->execute(
            new CompiledDatabaseCommand(
                sql: 'UPDATE execution_items SET name = :name WHERE active = :active',
                resultType: DatabaseResultType::AffectedRows,
            ),
            new RuntimeBindingSet([
                ':name' => 'VoltStack Updated',
                ':active' => true,
            ]),
            $context,
        );

        return [
            'default_connection' => $this->database->defaultConnectionName(),
            'rows_type' => $rows->type->value,
            'scalar_type' => $scalar->type->value,
            'update_type' => $update->type->value,
            'total' => $scalar->scalar(),
            'first_name' => $rows->first()['name'] ?? null,
            'updated_rows' => $update->affectedRows,
            'same_executor_instance' => spl_object_id($this->queries) === spl_object_id($queries),
        ];
    }
}

final class DatabaseQueryExecutionErrorController
{
    public function __construct(
        private readonly QueryExecutorInterface $queries,
    ) {
    }

    public function __invoke(): array
    {
        try {
            $this->queries->execute(new CompiledDatabaseCommand(
                sql: 'SELECT * FROM missing_table',
                resultType: DatabaseResultType::Rows,
            ));
        } catch (ExecutionException $exception) {
            return [
                'category' => $exception->failure()->category,
                'phase' => $exception->failure()->phase,
                'message' => $exception->getMessage(),
                'sql' => $exception->failure()->diagnostics['sql'] ?? null,
            ];
        }

        return ['unexpected' => true];
    }
}
