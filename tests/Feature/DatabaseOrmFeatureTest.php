<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\ORM\EntityRepository;
use Quantum\Database\ORM\EntityState;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\ORM\Model;
use Quantum\Database\Schema\Builder\TableBlueprint;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class DatabaseOrmFeatureTest extends TestCase
{
    private string $basePath;
    private string $databasePath;
    private ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-orm-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(ConnectionManagerInterface::class)->disconnectAll();
            $this->app = null;
        }

        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_entity_manager_repository_and_metadata_work_together_inside_one_scope(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/entity-manager', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active');
            }, true);

            $metadata = $app->make(EntityMetadataRegistry::class);
            self::assertTrue($metadata->has(OrmUser::class));
            self::assertTrue($metadata->has(OrmPost::class));
            self::assertFalse($metadata->has(\stdClass::class));

            $userMetadata = $metadata->for(OrmUser::class);
            self::assertSame('orm_users', $userMetadata->table);
            self::assertSame('id', $userMetadata->identifier->column);
            self::assertSame(OrmUserRepository::class, $userMetadata->repositoryClass);
            self::assertSame('string', $userMetadata->field('name')->type);
            self::assertSame('bool', $userMetadata->field('active')->type);

            $postMetadata = $metadata->for(OrmPost::class);
            self::assertSame('orm_posts', $postMetadata->table);

            $manager = $database->entityManager();
            $repository = $database->repository(OrmUser::class);

            self::assertInstanceOf(OrmUserRepository::class, $repository);
            self::assertInstanceOf(EntityRepositoryInterface::class, $repository);

            $volt = new OrmUser();
            $volt->name = 'VoltStack';
            $volt->active = true;

            $quantum = new OrmUser();
            $quantum->name = 'Quantum';
            $quantum->active = false;

            $manager->persist($volt);
            $manager->persist($quantum);
            $manager->flush();

            self::assertIsInt($volt->id);
            self::assertIsInt($quantum->id);
            self::assertSame(EntityState::Managed, $manager->state($volt));
            self::assertTrue($manager->contains($volt));
            self::assertSame($volt, $manager->find(OrmUser::class, $volt->id));

            $volt->name = 'VoltStack Updated';
            $manager->flush();

            $byCriteria = $repository->findOneBy(['name' => 'VoltStack Updated']);
            self::assertSame($volt, $byCriteria);

            $activeNames = $repository->activeNames();
            self::assertSame(['VoltStack Updated'], $activeNames);

            $manager->clear();

            $reloaded = $manager->find(OrmUser::class, $volt->id);
            self::assertInstanceOf(OrmUser::class, $reloaded);
            self::assertNotSame($volt, $reloaded);
            self::assertSame('VoltStack Updated', $reloaded->name);
            self::assertTrue($reloaded->active);

            $all = $database->repository(OrmUser::class)->findBy([], ['name' => 'asc']);
            self::assertCount(2, $all);
            self::assertSame(['Quantum', 'VoltStack Updated'], array_map(
                static fn(OrmUser $user): string => $user->name,
                $all,
            ));
            self::assertSame(
                $reloaded,
                $database->repository(OrmUser::class)->find($reloaded->id),
            );
        } finally {
            $scope->end();
        }
    }

    public function test_model_api_uses_the_same_scoped_entity_manager_for_crud_operations(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/model', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);

            $manager = $database->entityManager();

            $first = OrmPost::create([
                'title' => 'First Post',
                'published' => true,
            ]);
            $second = OrmPost::create([
                'title' => 'Draft Post',
                'published' => false,
            ]);

            self::assertIsInt($first->id);
            self::assertIsInt($second->id);
            self::assertSame($first, OrmPost::find($first->id));

            $first->title = 'Updated Post';
            self::assertTrue($first->save());

            $published = OrmPost::query()
                ->where('published', true)
                ->orderBy('title')
                ->get();

            self::assertCount(1, $published);
            self::assertInstanceOf(OrmPost::class, $published[0]);
            self::assertSame($first, $published[0]);
            self::assertSame('Updated Post', $published[0]->title);

            self::assertTrue($first->delete());
            self::assertSame(EntityState::Detached, $manager->state($first));

            $manager->clear();

            self::assertNull(OrmPost::find($first->id));
            self::assertInstanceOf(OrmPost::class, OrmPost::find($second->id));
        } finally {
            $scope->end();
        }
    }

    public function test_orm_types_round_trip_datetime_enum_and_json_values(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/types', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_events', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('status');
                $table->string('occurred_at');
                $table->string('payload');
            }, true);

            $metadata = $app->make(EntityMetadataRegistry::class)->for(OrmEvent::class);
            self::assertSame('enum', $metadata->field('status')->type);
            self::assertSame(OrmEventStatus::class, $metadata->field('status')->enumClass);
            self::assertSame('datetime_immutable', $metadata->field('occurredAt')->type);
            self::assertSame('json', $metadata->field('payload')->type);

            $createdAt = new DateTimeImmutable('2026-09-24T10:30:15+00:00');
            $event = OrmEvent::create([
                'name' => 'build.finished',
                'status' => OrmEventStatus::Published,
                'occurredAt' => $createdAt,
                'payload' => ['ok' => true, 'version' => 8],
            ]);

            self::assertIsInt($event->id);
            self::assertSame(OrmEventStatus::Published, $event->status);
            self::assertSame($createdAt->format(DATE_ATOM), $event->occurredAt->format(DATE_ATOM));
            self::assertSame(['ok' => true, 'version' => 8], $event->payload);

            $rawRow = $database->table('orm_events')->where('id', $event->id)->first();
            self::assertSame('published', $rawRow['status'] ?? null);
            self::assertSame($createdAt->format(DATE_ATOM), $rawRow['occurred_at'] ?? null);
            self::assertSame('{"ok":true,"version":8}', $rawRow['payload'] ?? null);

            $manager = $database->entityManager();
            $manager->clear();

            $reloaded = OrmEvent::query()
                ->where('status', OrmEventStatus::Published)
                ->first();

            self::assertInstanceOf(OrmEvent::class, $reloaded);
            self::assertSame(OrmEventStatus::Published, $reloaded->status);
            self::assertInstanceOf(DateTimeImmutable::class, $reloaded->occurredAt);
            self::assertSame($createdAt->format(DATE_ATOM), $reloaded->occurredAt->format(DATE_ATOM));
            self::assertSame(['ok' => true, 'version' => 8], $reloaded->payload);

            $reloaded->status = OrmEventStatus::Draft;
            $reloaded->payload = ['ok' => false, 'version' => 9];
            self::assertTrue($reloaded->save());

            $manager->clear();

            $updated = OrmEvent::find($event->id);
            self::assertInstanceOf(OrmEvent::class, $updated);
            self::assertSame(OrmEventStatus::Draft, $updated->status);
            self::assertSame(['ok' => false, 'version' => 9], $updated->payload);
        } finally {
            $scope->end();
        }
    }

    private function makeApp(): Application
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'default');
        $config->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        return $this->app = $app;
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

#[Entity(repository: OrmUserRepository::class)]
final class OrmUser
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[Column]
    public bool $active = false;
}

final class OrmUserRepository extends EntityRepository
{
    /**
     * @return list<string>
     */
    public function activeNames(): array
    {
        return array_map(
            static fn(OrmUser $user): string => $user->name,
            $this->findBy(['active' => true], ['name' => 'asc']),
        );
    }
}

final class OrmPost extends Model
{
    protected static string $table = 'orm_posts';

    #[Id]
    public ?int $id = null;

    #[Column]
    public string $title;

    #[Column]
    public bool $published = false;
}

enum OrmEventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final class OrmEvent extends Model
{
    protected static string $table = 'orm_events';

    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[Column(type: 'enum', enumType: OrmEventStatus::class)]
    public OrmEventStatus $status;

    #[Column(name: 'occurred_at', type: 'datetime_immutable')]
    public DateTimeImmutable $occurredAt;

    #[Column(type: 'json')]
    public array $payload = [];
}
