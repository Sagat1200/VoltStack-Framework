<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\TransactionManagerInterface;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Schema\SchemaManager;
use Quantum\Database\Transaction\TransactionException;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

final class DatabaseTransactionManagerTest extends TestCase
{
    private string $basePath;
    private string $databasePath;
    private ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-transactions-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
        DatabaseTransactionScopeCapture::$countAfterScope = null;
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(ConnectionManagerInterface::class)->disconnectAll();
            $this->app = null;
        }

        DatabaseTransactionScopeCapture::$countAfterScope = null;
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_commits_rolls_back_and_supports_nested_savepoints(): void
    {
        $app = $this->makeApp();
        $schema = $app->make(SchemaManager::class);
        $schema->create('transaction_items', function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('name');
        }, true);

        $transactions = $app->make(TransactionManagerInterface::class);
        $db = $app->make(DatabaseQueryManager::class);

        $transactions->begin();
        $db->table('transaction_items')->insert(['name' => 'committed']);
        $transactions->commit();

        self::assertSame(['committed'], $this->names($app));

        $transactions->begin();
        $db->table('transaction_items')->insert(['name' => 'rolled-back']);
        $transactions->rollback();

        self::assertSame(['committed'], $this->names($app));

        $transactions->begin();
        $db->table('transaction_items')->insert(['name' => 'outer']);
        $transactions->begin();
        $db->table('transaction_items')->insert(['name' => 'inner']);
        self::assertTrue($transactions->isActive());
        self::assertSame(2, $transactions->current()?->depth());
        $transactions->rollback();
        self::assertSame(1, $transactions->current()?->depth());
        $transactions->commit();

        self::assertSame(['committed', 'outer'], $this->names($app));
    }

    public function test_it_rejects_commit_for_rollback_only_transactions(): void
    {
        $app = $this->makeApp();
        $schema = $app->make(SchemaManager::class);
        $schema->create('transaction_items', function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('name');
        }, true);

        $transactions = $app->make(TransactionManagerInterface::class);
        $db = $app->make(DatabaseQueryManager::class);

        $transactions->begin();
        $db->table('transaction_items')->insert(['name' => 'rollback-only']);
        $transactions->markRollbackOnly();

        try {
            $transactions->commit();
            self::fail('Expected rollback-only transaction commit to fail.');
        } catch (TransactionException $exception) {
            self::assertStringContainsString('rollback-only', $exception->getMessage());
        }

        self::assertSame([], $this->names($app));
        self::assertFalse($transactions->isActive());
    }

    public function test_scope_end_rolls_back_open_transactions(): void
    {
        $app = $this->makeApp();
        $schema = $app->make(SchemaManager::class);
        $schema->create('scope_transaction_items', function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('name');
        }, true);

        $app->onScopeEnd(function () use ($app): void {
            DatabaseTransactionScopeCapture::$countAfterScope = $this->countRows(
                $app->basePath('database.sqlite'),
                'scope_transaction_items',
            );
        });

        $router = $app->make(Router::class);
        $router->get('/database/transaction-scope', DatabaseTransactionScopeController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/database/transaction-scope'));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['active_transaction']);
        self::assertSame(1, $payload['depth']);
        self::assertSame(0, DatabaseTransactionScopeCapture::$countAfterScope);
    }

    private function makeApp(): Application
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        return $this->app = $app;
    }

    /**
     * @return list<string>
     */
    private function names(Application $app): array
    {
        $rows = $app->make(DatabaseQueryManager::class)
            ->table('transaction_items')
            ->select('name')
            ->orderBy('name')
            ->get()
            ->rows();

        return array_values(array_map(static fn(array $row): string => (string) $row['name'], $rows));
    }

    private function countRows(string $databasePath, string $table): int
    {
        $pdo = new PDO('sqlite:' . $databasePath);

        return (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn();
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

final class DatabaseTransactionScopeController
{
    public function __construct(
        private readonly TransactionManagerInterface $transactions,
        private readonly DatabaseQueryManager $db,
    ) {
    }

    public function __invoke(): array
    {
        $this->transactions->begin();
        $this->db->table('scope_transaction_items')->insert(['name' => 'uncommitted']);

        return [
            'active_transaction' => $this->transactions->isActive(),
            'depth' => $this->transactions->current()?->depth(),
        ];
    }
}

final class DatabaseTransactionScopeCapture
{
    public static ?int $countAfterScope = null;
}
