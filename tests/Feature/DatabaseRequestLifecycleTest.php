<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\Attribute as ORM;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;
use VoltStack\Framework\Provider\OrmServiceProvider;

final class DatabaseRequestLifecycleTest extends TestCase
{
    private string $basePath;
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-request-' . $suffix;
        $this->sqlitePath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'runtime.sqlite';

        mkdir($this->basePath, 0777, true);
        mkdir(dirname($this->sqlitePath), 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_entity_manager_flushes_automatically_when_scope_ends(): void
    {
        $app = $this->makeDatabaseApp(autoFlushOnTerminate: true);
        $this->createRecordsTable($app);

        $router = $app->make(Router::class);
        $router->get('/records/create', AutoFlushRecordController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/records/create'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['scope_ping']);
        self::assertSame(0, $payload['count_during_request']);
        self::assertSame(1, $this->countPersistedRecords($app));
    }

    public function test_entity_manager_does_not_flush_automatically_when_disabled(): void
    {
        $app = $this->makeDatabaseApp(autoFlushOnTerminate: false);
        $this->createRecordsTable($app);

        $router = $app->make(Router::class);
        $router->get('/records/create', AutoFlushRecordController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/records/create'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['scope_ping']);
        self::assertSame(0, $payload['count_during_request']);
        self::assertSame(0, $this->countPersistedRecords($app));
    }

    public function test_http_runtime_can_create_and_read_a_record_across_two_requests(): void
    {
        $app = $this->makeDatabaseApp(autoFlushOnTerminate: true);
        $this->createRecordsTable($app);

        $router = $app->make(Router::class);
        $router->get('/records/create', CreateAndFlushRecordController::class);
        $router->get('/records/{id}', ReadPersistedRecordController::class);

        $kernel = $app->make(HttpKernel::class);

        $createResponse = $kernel->handle(Request::create('/records/create'));
        /** @var array<string, mixed> $createPayload */
        $createPayload = json_decode($createResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        $id = $createPayload['record_id'] ?? null;
        self::assertTrue($createPayload['scope_ping']);
        self::assertIsInt($id);
        self::assertSame($createPayload['runtime_request_id'], $createPayload['database_request_id']);

        $readResponse = $kernel->handle(Request::create('/records/' . $id));
        /** @var array<string, mixed> $readPayload */
        $readPayload = json_decode($readResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $this->countPersistedRecords($app));
        self::assertTrue($readPayload['found']);
        self::assertSame($id, $readPayload['record_id']);
        self::assertSame('created-from-request', $readPayload['name']);
        self::assertSame($readPayload['runtime_request_id'], $readPayload['database_request_id']);
        self::assertNotSame($createPayload['runtime_request_id'], $readPayload['runtime_request_id']);
    }

    private function makeDatabaseApp(bool $autoFlushOnTerminate): Application
    {
        $app = new Application($this->basePath);

        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->replace([
            'app' => [
                'env' => 'testing',
                'debug' => true,
            ],
            'database' => [
                'default' => 'primary',
                'connections' => [
                    'primary' => [
                        'driver' => 'sqlite',
                        'path' => $this->sqlitePath,
                        'memory' => false,
                        'foreign_keys' => true,
                    ],
                ],
                'metadata' => [
                    'entity_paths' => [],
                    'entities' => [
                        TestAutoFlushRecord::class,
                    ],
                    'cache_dir' => $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'orm' . DIRECTORY_SEPARATOR . 'metadata',
                    'custom_types' => [],
                ],
                'timeouts' => [
                    'soft_timeout_ms' => 30000,
                    'max_idle_ms_before_ping' => 0,
                ],
                'query_limits' => [
                    'max_rows' => 100000,
                    'max_depth' => 32,
                ],
                'security' => [
                    'redact_sensitive' => true,
                    'policies' => [
                        'soft_delete_filter' => true,
                    ],
                ],
                'orm' => [
                    'auto_flush_on_terminate' => $autoFlushOnTerminate,
                ],
            ],
        ]);

        $app->register(DatabaseServiceProvider::class);
        $app->register(OrmServiceProvider::class);
        $app->boot();

        return $app;
    }

    private function createRecordsTable(Application $app): void
    {
        $app->make(\Quantum\Database\Dbal\Contract\ConnectionInterface::class)->executeStatement(
            'CREATE TABLE IF NOT EXISTS test_auto_flush_records (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'
        );
    }

    private function countPersistedRecords(Application $app): int
    {
        $row = $app->make(\Quantum\Database\Dbal\Contract\ConnectionInterface::class)
            ->executeQuery('SELECT COUNT(*) AS aggregate_count FROM test_auto_flush_records')
            ->fetchOneAssoc();

        return (int) ($row['aggregate_count'] ?? 0);
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

            @unlink($target);
        }

        @rmdir($path);
    }
}

final class AutoFlushRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    )
    {
    }

    public function __invoke(): array
    {
        $record = new TestAutoFlushRecord('created-from-request');
        $this->em->persist($record);

        return [
            'scope_ping' => \VoltStack\Runtime\Context\RuntimeContext::current()?->get('database.scope_ping', false) ?? false,
            'count_during_request' => $this->em->count(TestAutoFlushRecord::class),
        ];
    }
}

final class CreateAndFlushRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        $record = new TestAutoFlushRecord('created-from-request');
        $this->em->persist($record);
        $this->em->flush();

        return [
            'scope_ping' => \VoltStack\Runtime\Context\RuntimeContext::current()?->get('database.scope_ping', false) ?? false,
            'record_id' => $record->id(),
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

final class ReadPersistedRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(string $id): array
    {
        $record = $this->em->find(TestAutoFlushRecord::class, (int) $id);

        return [
            'found' => $record instanceof TestAutoFlushRecord,
            'record_id' => $record?->id(),
            'name' => $record?->name(),
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

#[ORM\Entity(table: 'test_auto_flush_records')]
final class TestAutoFlushRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string')]
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }
}