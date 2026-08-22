<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\Attribute as ORM;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\ChangeSet;
use Quantum\Database\Orm\UnitOfWork\Event\AbstractLifecycleListener;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;
use VoltStack\Framework\Provider\OrmServiceProvider;

final class DatabaseLifecycleRuntimeTest extends TestCase
{
    private string $basePath;
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        LifecycleRuntimeCollector::$events = [];

        $suffix = bin2hex(random_bytes(6));
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-lifecycle-' . $suffix;
        $this->sqlitePath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'runtime.sqlite';

        mkdir($this->basePath, 0777, true);
        mkdir(dirname($this->sqlitePath), 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'orm' . DIRECTORY_SEPARATOR . 'metadata', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_http_runtime_dispatches_insert_lifecycle_events_during_auto_flush_on_scope_end(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/lifecycle/create-auto', CreateLifecycleRecordController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/lifecycle/create-auto'));
        /** @var array<string,mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        $events = LifecycleRuntimeCollector::$events;
        $row = $app->make(ConnectionInterface::class)
            ->executeQuery('SELECT id, name FROM lifecycle_runtime_records ORDER BY id DESC LIMIT 1')
            ->fetchOneAssoc();

        self::assertSame(200, $response->statusCode());
        self::assertSame(['preFlush', 'postInsert', 'postFlush'], array_column($events, 'name'));
        self::assertSame(LifecycleRuntimeRecord::class, $events[1]['entity_class'] ?? null);
        self::assertSame('lifecycle-auto', $events[1]['entity_name'] ?? null);
        self::assertSame($payload['runtime_request_id'], $events[0]['request_id'] ?? null);
        self::assertSame($payload['database_request_id'], $events[2]['request_id'] ?? null);
        self::assertSame('lifecycle-auto', $row['name'] ?? null);
    }

    public function test_http_runtime_dispatches_update_and_delete_lifecycle_events_with_change_sets(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/lifecycle/create', CreateAndFlushLifecycleRecordController::class);
        $router->get('/lifecycle/update/{id}', UpdateLifecycleRecordController::class);
        $router->get('/lifecycle/delete/{id}', DeleteLifecycleRecordController::class);
        $router->get('/lifecycle/count', CountLifecycleRecordsController::class);

        $kernel = $app->make(HttpKernel::class);

        $createResponse = $kernel->handle(Request::create('/lifecycle/create'));
        /** @var array<string,mixed> $createPayload */
        $createPayload = json_decode($createResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $id = (int) $createPayload['record_id'];

        LifecycleRuntimeCollector::$events = [];
        $updateResponse = $kernel->handle(Request::create('/lifecycle/update/' . $id));
        /** @var array<string,mixed> $updatePayload */
        $updatePayload = json_decode($updateResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $updateEvents = LifecycleRuntimeCollector::$events;

        self::assertSame(200, $updateResponse->statusCode());
        self::assertSame(['preFlush', 'postUpdate', 'postFlush'], array_column($updateEvents, 'name'));
        self::assertSame(['name'], $updateEvents[1]['changed_properties'] ?? null);
        self::assertSame('lifecycle-created', $updateEvents[1]['old_values']['name'] ?? null);
        self::assertSame('lifecycle-updated', $updateEvents[1]['new_values']['name'] ?? null);
        self::assertSame($updatePayload['runtime_request_id'], $updateEvents[0]['request_id'] ?? null);

        LifecycleRuntimeCollector::$events = [];
        $deleteResponse = $kernel->handle(Request::create('/lifecycle/delete/' . $id));
        /** @var array<string,mixed> $deletePayload */
        $deletePayload = json_decode($deleteResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $deleteEvents = LifecycleRuntimeCollector::$events;

        $countResponse = $kernel->handle(Request::create('/lifecycle/count'));
        /** @var array<string,mixed> $countPayload */
        $countPayload = json_decode($countResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $deleteResponse->statusCode());
        self::assertSame(['preFlush', 'postDelete', 'postFlush'], array_column($deleteEvents, 'name'));
        self::assertSame(LifecycleRuntimeRecord::class, $deleteEvents[1]['entity_class'] ?? null);
        self::assertSame($deletePayload['database_request_id'], $deleteEvents[2]['request_id'] ?? null);
        self::assertSame(0, $countPayload['count']);
    }

    private function makeDatabaseApp(): Application
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
                        LifecycleRuntimeRecord::class,
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
                    'auto_flush_on_terminate' => true,
                    'lifecycle' => [
                        'listeners' => [
                            LifecycleRuntimeCollector::class,
                        ],
                    ],
                ],
            ],
        ]);

        $app->register(DatabaseServiceProvider::class);
        $app->register(OrmServiceProvider::class);
        $app->boot();

        return $app;
    }

    private function createTables(Application $app): void
    {
        $app->make(ConnectionInterface::class)->executeStatement(
            'CREATE TABLE IF NOT EXISTS lifecycle_runtime_records (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'
        );
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

final class CreateLifecycleRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        $record = new LifecycleRuntimeRecord();
        $record->name = 'lifecycle-auto';

        $this->em->persist($record);

        return [
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

final class CreateAndFlushLifecycleRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        $record = new LifecycleRuntimeRecord();
        $record->name = 'lifecycle-created';

        $this->em->persist($record);
        $this->em->flush();

        return [
            'record_id' => $record->id,
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

final class UpdateLifecycleRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(string $id): array
    {
        /** @var LifecycleRuntimeRecord|null $record */
        $record = $this->em->find(LifecycleRuntimeRecord::class, (int) $id);
        if (!$record instanceof LifecycleRuntimeRecord) {
            return [
                'updated' => false,
                'database_request_id' => $this->databaseContext->requestId,
            ];
        }

        $record->name = 'lifecycle-updated';
        $this->em->flush();

        return [
            'updated' => true,
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

final class DeleteLifecycleRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(string $id): array
    {
        /** @var LifecycleRuntimeRecord|null $record */
        $record = $this->em->find(LifecycleRuntimeRecord::class, (int) $id);
        if (!$record instanceof LifecycleRuntimeRecord) {
            return [
                'deleted' => false,
                'database_request_id' => $this->databaseContext->requestId,
            ];
        }

        $this->em->remove($record);
        $this->em->flush();

        return [
            'deleted' => true,
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

final class CountLifecycleRecordsController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(): array
    {
        return [
            'count' => $this->em->count(LifecycleRuntimeRecord::class),
        ];
    }
}

final class LifecycleRuntimeCollector extends AbstractLifecycleListener
{
    /** @var list<array<string,mixed>> */
    public static array $events = [];

    public function preFlush(EntityManagerInterface $em): void
    {
        self::$events[] = [
            'name' => 'preFlush',
            'request_id' => $em->getContext()->requestId,
            'tenant_id' => $em->getTenantId(),
        ];
    }

    public function postInsert(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void
    {
        self::$events[] = [
            'name' => 'postInsert',
            'entity_class' => $entity::class,
            'entity_id' => $entity->id ?? null,
            'entity_name' => $entity->name ?? null,
            'table' => $meta->tableName,
            'request_id' => $em->getContext()->requestId,
        ];
    }

    public function postUpdate(
        object $entity,
        CompiledEntityMetadata $meta,
        ?ChangeSet $changeSet,
        EntityManagerInterface $em,
    ): void {
        self::$events[] = [
            'name' => 'postUpdate',
            'entity_class' => $entity::class,
            'entity_id' => $entity->id ?? null,
            'table' => $meta->tableName,
            'changed_properties' => $changeSet?->changedPropertyNames ?? [],
            'old_values' => $changeSet?->oldValues ?? [],
            'new_values' => $changeSet?->newValues ?? [],
            'request_id' => $em->getContext()->requestId,
        ];
    }

    public function postDelete(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void
    {
        self::$events[] = [
            'name' => 'postDelete',
            'entity_class' => $entity::class,
            'entity_id' => $entity->id ?? null,
            'table' => $meta->tableName,
            'request_id' => $em->getContext()->requestId,
        ];
    }

    public function postFlush(EntityManagerInterface $em): void
    {
        self::$events[] = [
            'name' => 'postFlush',
            'request_id' => $em->getContext()->requestId,
            'tenant_id' => $em->getTenantId(),
        ];
    }
}

#[ORM\Entity(table: 'lifecycle_runtime_records')]
final class LifecycleRuntimeRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string')]
    public string $name;
}
