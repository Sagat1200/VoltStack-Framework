<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\Attribute as ORM;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;
use VoltStack\Framework\Provider\OrmServiceProvider;

final class DatabaseTenantSoftDeleteRuntimeTest extends TestCase
{
    private string $basePath;
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-tenant-' . $suffix;
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

    public function test_http_runtime_binds_tenant_from_header_and_filters_reads_by_tenant_and_soft_delete(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/tenant-records/create/{kind}', CreateTenantRecordController::class);
        $router->get('/tenant-records/read/{id}', ReadTenantRecordController::class);
        $router->get('/tenant-records/latest', ReadLatestTenantRecordController::class);
        $router->get('/tenant-records/count', CountTenantRecordsController::class);

        $kernel = $app->make(HttpKernel::class);

        $tenantAVisible = $this->dispatchTenant($kernel, '/tenant-records/create/visible', 'tenant-a');
        $tenantBVisible = $this->dispatchTenant($kernel, '/tenant-records/create/visible', 'tenant-b');
        $tenantADeleted = $this->dispatchTenant($kernel, '/tenant-records/create/deleted', 'tenant-a');

        self::assertSame('tenant-a', $tenantAVisible['database_tenant_id']);
        self::assertSame('tenant-a', $tenantAVisible['entity_manager_tenant_id']);
        self::assertSame('tenant-a', $tenantAVisible['runtime_tenant_id']);

        $tenantALatest = $this->dispatchTenant($kernel, '/tenant-records/latest', 'tenant-a');
        $tenantBLatest = $this->dispatchTenant($kernel, '/tenant-records/latest', 'tenant-b');
        $tenantACount = $this->dispatchTenant($kernel, '/tenant-records/count', 'tenant-a');
        $tenantBCount = $this->dispatchTenant($kernel, '/tenant-records/count', 'tenant-b');
        $crossTenantRead = $this->dispatchTenant($kernel, '/tenant-records/read/' . $tenantBVisible['record_id'], 'tenant-a');
        $softDeletedRead = $this->dispatchTenant($kernel, '/tenant-records/read/' . $tenantADeleted['record_id'], 'tenant-a');

        self::assertTrue($tenantALatest['found']);
        self::assertSame('tenant-a-visible', $tenantALatest['name']);
        self::assertSame('tenant-a', $tenantALatest['tenant_id']);
        self::assertSame(1, $tenantACount['count']);

        self::assertTrue($tenantBLatest['found']);
        self::assertSame('tenant-b-visible', $tenantBLatest['name']);
        self::assertSame('tenant-b', $tenantBLatest['tenant_id']);
        self::assertSame(1, $tenantBCount['count']);

        self::assertFalse($crossTenantRead['found']);
        self::assertFalse($softDeletedRead['found']);
    }

    public function test_http_runtime_excludes_entity_after_soft_delete_update_in_next_request(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/tenant-records/create/{kind}', CreateTenantRecordController::class);
        $router->get('/tenant-records/read/{id}', ReadTenantRecordController::class);
        $router->get('/tenant-records/count', CountTenantRecordsController::class);
        $router->get('/tenant-records/soft-delete/{id}', SoftDeleteTenantRecordController::class);

        $kernel = $app->make(HttpKernel::class);

        $created = $this->dispatchTenant($kernel, '/tenant-records/create/visible', 'tenant-a');
        $beforeDeleteCount = $this->dispatchTenant($kernel, '/tenant-records/count', 'tenant-a');
        $deleted = $this->dispatchTenant($kernel, '/tenant-records/soft-delete/' . $created['record_id'], 'tenant-a');
        $afterDeleteCount = $this->dispatchTenant($kernel, '/tenant-records/count', 'tenant-a');
        $readDeleted = $this->dispatchTenant($kernel, '/tenant-records/read/' . $created['record_id'], 'tenant-a');

        self::assertSame(1, $beforeDeleteCount['count']);
        self::assertTrue($deleted['soft_deleted']);
        self::assertSame('tenant-a', $deleted['database_tenant_id']);
        self::assertSame(0, $afterDeleteCount['count']);
        self::assertFalse($readDeleted['found']);
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
                        TenantScopedRecord::class,
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
            'CREATE TABLE IF NOT EXISTS tenant_runtime_records (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, tenant_id TEXT NOT NULL, deleted_at TEXT NULL)'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function dispatchTenant(HttpKernel $kernel, string $uri, string $tenantId): array
    {
        $response = $kernel->handle(Request::create(
            $uri,
            'GET',
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X_TENANT_ID' => $tenantId],
        ));

        /** @var array<string,mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
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

final class CreateTenantRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(string $kind): array
    {
        $tenantId = $this->databaseContext->tenantId ?? 'missing-tenant';

        $record = new TenantScopedRecord();
        $record->name = $tenantId . '-' . $kind;
        $record->tenantId = $tenantId;

        if ($kind === 'deleted') {
            $record->deletedAt = '2026-08-21 12:00:00';
        }

        $this->em->persist($record);
        $this->em->flush();

        return [
            'record_id' => $record->id,
            'database_tenant_id' => $this->databaseContext->tenantId,
            'entity_manager_tenant_id' => $this->em->getTenantId(),
            'runtime_tenant_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->get('database.tenant_id'),
        ];
    }
}

final class ReadTenantRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(string $id): array
    {
        $record = $this->em->find(TenantScopedRecord::class, (int) $id);

        return [
            'found' => $record instanceof TenantScopedRecord,
            'record_id' => $record?->id,
            'name' => $record?->name,
            'tenant_id' => $record?->tenantId,
            'database_tenant_id' => $this->databaseContext->tenantId,
        ];
    }
}

final class ReadLatestTenantRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        /** @var TenantScopedRecord|null $record */
        $record = $this->em->findOneBy(TenantScopedRecord::class, [], ['id' => 'DESC']);

        return [
            'found' => $record instanceof TenantScopedRecord,
            'record_id' => $record?->id,
            'name' => $record?->name,
            'tenant_id' => $record?->tenantId,
            'database_tenant_id' => $this->databaseContext->tenantId,
        ];
    }
}

final class CountTenantRecordsController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        return [
            'count' => $this->em->count(TenantScopedRecord::class),
            'database_tenant_id' => $this->databaseContext->tenantId,
        ];
    }
}

final class SoftDeleteTenantRecordController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(string $id): array
    {
        /** @var TenantScopedRecord|null $record */
        $record = $this->em->find(TenantScopedRecord::class, (int) $id);
        if (!$record instanceof TenantScopedRecord) {
            return [
                'soft_deleted' => false,
                'database_tenant_id' => $this->databaseContext->tenantId,
            ];
        }

        $record->deletedAt = '2026-08-21 13:00:00';
        $this->em->flush();

        return [
            'soft_deleted' => true,
            'record_id' => $record->id,
            'database_tenant_id' => $this->databaseContext->tenantId,
        ];
    }
}

#[ORM\Entity(table: 'tenant_runtime_records')]
final class TenantScopedRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string')]
    public string $name;

    #[ORM\Column(name: 'tenant_id', type: 'string')]
    #[ORM\TenantColumn(column: 'tenant_id')]
    public string $tenantId;

    #[ORM\Column(name: 'deleted_at', type: 'string', nullable: true)]
    #[ORM\SoftDelete(column: 'deleted_at')]
    public ?string $deletedAt = null;
}
