<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Operation\DatabaseOperationException;
use Quantum\Database\Orm\EntityManager\EntityManager;
use Quantum\Database\Orm\Metadata\Attribute as ORM;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;
use VoltStack\Framework\Provider\OrmServiceProvider;

final class DatabaseOperationalPropagationTest extends TestCase
{
    private string $basePath;
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-operational-' . $suffix;
        $this->sqlitePath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'runtime.sqlite';

        mkdir($this->basePath, 0777, true);
        mkdir(dirname($this->sqlitePath), 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_select_query_builder_records_operational_plan_and_diagnostic(): void
    {
        $app = $this->makeDatabaseApp(maxRows: 10);
        $this->seedRecords($app);

        /** @var EntityManager $em */
        $em = $app->make(EntityManager::class);
        $qb = $em->createQueryBuilder(OperationalRecord::class, 'r')
            ->select(['r.id', 'r.name'])
            ->orderBy('r.id');

        $rows = $qb->fetchAllAssociative();
        $plan = $qb->getLastOperationPlan();
        $diagnostic = $qb->getLastDiagnostic();

        self::assertCount(2, $rows);
        self::assertNotNull($plan);
        self::assertNotNull($diagnostic);
        self::assertSame('raw_query', $plan->operation->kind->value);
        self::assertSame('completed', $diagnostic->outcome);
        self::assertSame(2, $diagnostic->rowsRead);

        self::assertSame(2, $em->count(OperationalRecord::class));
        self::assertNotNull($em->getLastReadDiagnostic());
        self::assertSame('completed', $em->getLastReadDiagnostic()?->outcome);
    }

    public function test_entity_manager_reads_inherit_runtime_budget_limits(): void
    {
        $app = $this->makeDatabaseApp(maxRows: 1);
        $this->seedRecords($app);

        /** @var EntityManager $em */
        $em = $app->make(EntityManager::class);

        try {
            $em->findAll(OperationalRecord::class);
            self::fail('Expected ORM read budget to block the query.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('resource_exhausted', $e->failure->value);
            self::assertSame('failed', $e->snapshot->outcome);
        }
    }

    public function test_entity_manager_write_side_records_operational_diagnostics(): void
    {
        $app = $this->makeDatabaseApp(maxRows: 10);
        $this->seedRecords($app);

        /** @var EntityManager $em */
        $em = $app->make(EntityManager::class);
        $record = new OperationalRecord();
        $record->name = 'gamma';

        $em->persist($record);
        $em->flush();

        $writePlan = $em->getLastWritePlan();
        $writeDiagnostic = $em->getLastWriteDiagnostic();

        self::assertNotNull($writePlan);
        self::assertNotNull($writeDiagnostic);
        self::assertSame('raw_execute', $writePlan->operation->kind->value);
        self::assertSame('completed', $writeDiagnostic->outcome);

        $em->clear();
        $loaded = $em->find(OperationalRecord::class, $record->id);
        $readPlan = $em->getLastReadPlan();
        $readDiagnostic = $em->getLastReadDiagnostic();

        self::assertInstanceOf(OperationalRecord::class, $loaded);
        self::assertNotNull($readPlan);
        self::assertNotNull($readDiagnostic);
        self::assertSame('raw_query', $readPlan->operation->kind->value);
        self::assertSame('completed', $readDiagnostic->outcome);
    }

    private function makeDatabaseApp(int $maxRows): Application
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
                        OperationalRecord::class,
                    ],
                    'cache_dir' => $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'orm' . DIRECTORY_SEPARATOR . 'metadata',
                    'custom_types' => [],
                ],
                'timeouts' => [
                    'soft_timeout_ms' => 30000,
                    'max_idle_ms_before_ping' => 0,
                ],
                'query_limits' => [
                    'max_rows' => $maxRows,
                    'max_depth' => 32,
                ],
                'observability' => [
                    'audit' => true,
                    'slow_query_ms' => 1,
                ],
                'resilience' => [
                    'retry_limit' => 1,
                    'retry_backoff_ms' => 0,
                    'circuit_breaker' => [
                        'failure_threshold' => 2,
                        'cooldown_ms' => 30000,
                    ],
                ],
                'security' => [
                    'redact_sensitive' => true,
                    'policies' => [
                        'soft_delete_filter' => true,
                    ],
                ],
                'orm' => [
                    'auto_flush_on_terminate' => false,
                ],
            ],
        ]);

        $app->register(DatabaseServiceProvider::class);
        $app->register(OrmServiceProvider::class);
        $app->boot();

        return $app;
    }

    private function seedRecords(Application $app): void
    {
        /** @var ConnectionInterface $connection */
        $connection = $app->make(ConnectionInterface::class);
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS operational_records (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'
        );
        $connection->executeStatement("INSERT INTO operational_records (name) VALUES ('alpha')");
        $connection->executeStatement("INSERT INTO operational_records (name) VALUES ('beta')");
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

#[ORM\Entity(table: 'operational_records')]
final class OperationalRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer', name: 'id')]
    #[ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(type: 'string', name: 'name')]
    public string $name = '';
}
