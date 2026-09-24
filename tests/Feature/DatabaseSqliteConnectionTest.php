<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Runtime\DatabaseContext;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

final class DatabaseSqliteConnectionTest extends TestCase
{
    private string $basePath;
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-sqlite-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
        DatabaseConnectionCapture::$connection = null;
        DatabaseConnectionCapture::$disconnectedAfterScope = null;
    }

    protected function tearDown(): void
    {
        DatabaseConnectionCapture::$connection = null;
        DatabaseConnectionCapture::$disconnectedAfterScope = null;
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_executes_sqlite_queries_and_disconnects_connections_after_the_request_scope(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        $app->onScopeEnd(function (): void {
            DatabaseConnectionCapture::$disconnectedAfterScope = DatabaseConnectionCapture::$connection?->isConnected() === false;
        });

        $router = $app->make(Router::class);
        $router->get('/database/sqlite', DatabaseSqliteConnectionController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/database/sqlite'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('default', $payload['default_connection']);
        self::assertSame('sqlite', $payload['platform']);
        self::assertSame('sqlite', $payload['dialect']);
        self::assertSame(1, $payload['count']);
        self::assertTrue($payload['same_connection_instance']);
        self::assertTrue($payload['connected_during_request']);
        self::assertTrue(DatabaseConnectionCapture::$disconnectedAfterScope);
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

final class DatabaseSqliteConnectionController
{
    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseContext $database,
    ) {
    }

    public function __invoke(ConnectionManagerInterface $connections): array
    {
        $connection = $this->connections->connection();
        DatabaseConnectionCapture::$connection = $connection;
        $pdo = $connection->pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS test_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO test_items (name) VALUES ('VoltStack')");

        $count = (int) $pdo->query('SELECT COUNT(*) FROM test_items')->fetchColumn();

        return [
            'default_connection' => $this->database->defaultConnectionName(),
            'platform' => $connection->platform()->id(),
            'dialect' => $connection->dialect()->id(),
            'count' => $count,
            'same_connection_instance' => spl_object_id($connection) === spl_object_id($connections->connection()),
            'connected_during_request' => $connection->isConnected(),
        ];
    }
}

final class DatabaseConnectionCapture
{
    public static ?object $connection = null;
    public static ?bool $disconnectedAfterScope = null;
}
