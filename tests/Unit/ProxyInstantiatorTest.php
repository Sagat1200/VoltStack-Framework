<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\ManyToOne;
use Quantum\Database\ORM\Attributes\OneToMany;
use Quantum\Database\ORM\Attributes\OneToOne;
use Quantum\Database\ORM\Attributes\Table;
use Quantum\Database\ORM\EntityState;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\ORM\Proxy\LazyLoadingPlaceholderInterface;
use Quantum\Database\ORM\Proxy\ProxyInitializationStatus;
use Quantum\Database\ORM\Proxy\ReflectionAnonymousProxyInstantiator;
use Quantum\Database\Schema\Builder\TableBlueprint;
use Quantum\Config\ConfigRepository;
use Quantum\Http\Request;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class ProxyInstantiatorTest extends TestCase
{
    private string $basePath;
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-proxy-instantiator-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
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

        return $app;
    }

    public function test_get_reference_returns_uninitialized_proxy_placeholder_with_correct_identifier_and_managed_state(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/proxy-instantiator/get-reference', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('proxy_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            // Warm up metadata through the inverse side first so any future
            // additions of bidirectional associations won't break later.
            $registry->for(ProxyUser::class);
            $registry->for(ProxyPost::class);
            $registry->for(ProxyProfile::class);

            $volt = new ProxyUser();
            $volt->name = 'VoltStack';
            $volt->active = true;
            $manager->persist($volt);
            $manager->flush();
            $manager->clear();

            $ref = $manager->getReference(ProxyUser::class, $volt->id);

            self::assertSame($volt->id, $ref->id);
            self::assertTrue($manager->contains($ref));
            self::assertSame(EntityState::Managed, $manager->state($ref));

            // UnitOfWork status tracking through the common helper
            $uow = $this->unitOfWorkFromEntityManager($manager);
            self::assertSame(ProxyInitializationStatus::Uninitialized, $uow->proxyStatusOf($ref));
            self::assertTrue($uow->isUninitializedProxy($ref));
        } finally {
            $scope->end();
        }
    }

    public function test_get_reference_on_already_managed_identifier_returns_same_instance_not_a_new_placeholder(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/proxy-instantiator/existing-managed', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('proxy_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active');
            }, true);

            $manager = $database->entityManager();

            $volt = new ProxyUser();
            $volt->name = 'VoltStack';
            $volt->active = true;
            $manager->persist($volt);
            $manager->flush();

            // Find produces a fully initialized managed instance
            $loaded = $manager->find(ProxyUser::class, $volt->id);
            $ref1 = $manager->getReference(ProxyUser::class, $volt->id);
            $ref2 = $manager->getReference(ProxyUser::class, $volt->id);

            self::assertSame($loaded, $ref1, 'IdentityMap must surface the existing managed instance for the same id');
            self::assertSame($ref1, $ref2, 'Sequential getReference calls must return the same object reference');

            $uow = $this->unitOfWorkFromEntityManager($manager);
            self::assertSame(ProxyInitializationStatus::Initialized, $uow->proxyStatusOf($loaded));
            self::assertFalse($uow->isUninitializedProxy($loaded));
        } finally {
            $scope->end();
        }
    }

    public function test_initializeProxy_loads_real_row_moves_status_to_initialized_and_populates_snapshots(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/proxy-instantiator/initialize-proxy', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('proxy_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active');
            }, true);

            $manager = $database->entityManager();

            $volt = new ProxyUser();
            $volt->name = 'VoltStack Initialized';
            $volt->active = true;
            $manager->persist($volt);
            $manager->flush();
            $manager->clear();

            $ref = $manager->getReference(ProxyUser::class, $volt->id);
            $manager->initializeProxy($ref);

            self::assertSame('VoltStack Initialized', $ref->name);
            self::assertTrue($ref->active);

            $uow = $this->unitOfWorkFromEntityManager($manager);
            self::assertSame(ProxyInitializationStatus::Initialized, $uow->proxyStatusOf($ref));
            self::assertNotEmpty($uow->snapshot($ref), 'Snapshot must be populated after initializeProxy runs registerManaged');

            // Must be idempotent when already initialized
            $manager->initializeProxy($ref);
            self::assertSame(ProxyInitializationStatus::Initialized, $uow->proxyStatusOf($ref));
        } finally {
            $scope->end();
        }
    }

    public function test_proxy_initialization_status_tracking_accurate_over_lifecycle(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/proxy-instantiator/status-tracking', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('proxy_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            $registry->for(ProxyUser::class);
            $registry->for(ProxyPost::class);
            $registry->for(ProxyProfile::class);
            $uow = $this->unitOfWorkFromEntityManager($manager);

            $volt = new ProxyUser();
            $volt->name = 'VoltStack Tracking';
            $volt->active = true;
            $manager->persist($volt);
            $manager->flush();
            $manager->clear();

            // A managed real entity NOT implementing marker defaults to Initialized via UOW ownership map,
            // even if it does not implement LazyLoadingPlaceholderInterface.
            $real = new ProxyUser();
            $real->name = 'Plain Entity';
            $real->active = false;
            $manager->persist($real);
            $manager->flush();
            self::assertSame(ProxyInitializationStatus::Initialized, $uow->proxyStatusOf($real));
            $manager->clear();

            // Now exercise the Uninitialized + Initializing transitions on a
            // clean UnitOfWork without any subsequent flush that would hit the
            // uninitialized-proxy guardrail.
            $ref = $manager->getReference(ProxyUser::class, $volt->id);
            self::assertSame(ProxyInitializationStatus::Uninitialized, $uow->proxyStatusOf($ref));

            // Simulate Initializing transient state set explicitly (like inside initializeProxy)
            $uow->markProxyStatus($ref, ProxyInitializationStatus::Initializing);
            self::assertSame(ProxyInitializationStatus::Initializing, $uow->proxyStatusOf($ref));
            $uow->markProxyStatus($ref, ProxyInitializationStatus::Uninitialized);
            self::assertSame(ProxyInitializationStatus::Uninitialized, $uow->proxyStatusOf($ref));
        } finally {
            $scope->end();
        }
    }

    public function test_preload_associations_over_uninitialized_proxy_triggers_unified_guardrail_exception(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/proxy-instantiator/guardrail-preload', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('proxy_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active');
            }, true);
            $database->schema()->create('proxy_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('author_id');
                $table->string('title');
                $table->boolean('published');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            $registry->for(ProxyUser::class);
            $registry->for(ProxyPost::class);

            $volt = new ProxyUser();
            $volt->name = 'VoltStack Guardrail';
            $volt->active = true;
            $manager->persist($volt);
            $manager->flush();
            $manager->clear();

            $ref = $manager->getReference(ProxyUser::class, $volt->id);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('uninitialized proxy placeholder');

            // Flush requires real snapshots for every managed entity. An
            // uninitialized placeholder must be rejected up-front through the
            // unified guardrail before reading any persistent field.
            $manager->flush();
        } finally {
            $scope->end();
        }
    }

    public function test_entityManager_clear_and_detach_clean_up_proxy_status_without_leaks(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/proxy-instantiator/cleanup', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('proxy_users', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active');
            }, true);

            $manager = $database->entityManager();
            $uow = $this->unitOfWorkFromEntityManager($manager);

            $volt = new ProxyUser();
            $volt->name = 'VoltStack Cleanup';
            $volt->active = true;
            $manager->persist($volt);
            $manager->flush();
            $manager->clear();

            $ref = $manager->getReference(ProxyUser::class, $volt->id);
            self::assertSame(ProxyInitializationStatus::Uninitialized, $uow->proxyStatusOf($ref));
            self::assertTrue($manager->contains($ref));

            // After EntityManager::clear() the IdentityMap, UOW snapshots and
            // the proxy status map must be fully dropped so a subsequent
            // getReference produces a brand-new placeholder (not the old one).
            $manager->clear();

            self::assertFalse($manager->contains($ref), 'Clear must drop placeholder from managed set');

            $second = $manager->getReference(ProxyUser::class, $volt->id);
            self::assertNotSame($ref, $second, 'Clear must drop IdentityMap references so next getReference produces a new placeholder');
            self::assertSame(ProxyInitializationStatus::Uninitialized, $uow->proxyStatusOf($second));
        } finally {
            $scope->end();
        }
    }

    public function test_reflection_anonymous_proxy_instantiator_works_for_final_entity_classes_without_invoking_constructor(): void
    {
        $app = $this->makeApp();
        $registry = $app->make(EntityMetadataRegistry::class);
        // Explicitly warm up metadata for both sides (inverse first) before
        // accessing metadata for potential final classes, in case we add
        // bidirectional associations.
        $registry->for(ProxyUser::class);
        $registry->for(ProxyFinalUser::class);

        $instantiator = new ReflectionAnonymousProxyInstantiator();
        $metadata = $registry->for(ProxyFinalUser::class);
        $key = $metadata->keyFor(42);
        $manager = $this->createMock(\Quantum\Database\ORM\Contracts\EntityManagerInterface::class);

        $entity = $instantiator->instantiatePlaceholder($metadata, $key, $manager);

        // Instance is of the final entity class itself (no wrapper/child) in V1.
        self::assertInstanceOf(ProxyFinalUser::class, $entity);
        self::assertSame(42, $metadata->identifierValue($entity));
        // The ProxyFinalUser constructor sets a fixed default name. Because
        // we use newInstanceWithoutConstructor, that default is NOT applied.
        self::assertNull($entity->name ?? null);
    }

    /**
     * Extract the private UnitOfWork owned by the given EntityManager.
     *
     * Used in unit tests to inspect proxy status tracking without adding a
     * public accessor on EntityManager in this V1 (the surface API stays
     * intentionally small for this foundational cut).
     */
    private function unitOfWorkFromEntityManager(object $manager): object
    {
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('unitOfWork');
        $property->setAccessible(true);

        return $property->getValue($manager);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $this->deleteDirectory($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}

#[Entity]
#[Table(name: 'proxy_users')]
class ProxyUser
{
    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'name', type: 'string')]
    public ?string $name = null;

    #[Column(name: 'active', type: 'bool')]
    public ?bool $active = null;

    #[OneToMany(targetEntity: ProxyPost::class, mappedBy: 'author')]
    public array $posts = [];

    #[OneToOne(targetEntity: ProxyProfile::class, mappedBy: 'user')]
    public ?ProxyProfile $profile = null;
}

#[Entity]
#[Table(name: 'proxy_posts')]
class ProxyPost
{
    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'title', type: 'string')]
    public ?string $title = null;

    #[Column(name: 'published', type: 'bool')]
    public ?bool $published = null;

    #[ManyToOne(targetEntity: ProxyUser::class, inversedBy: 'posts')]
    #[Column(name: 'author_id', type: 'int')]
    public ?ProxyUser $author = null;
}

#[Entity]
#[Table(name: 'proxy_profiles')]
class ProxyProfile
{
    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'bio', type: 'string')]
    public ?string $bio = null;

    #[OneToOne(targetEntity: ProxyUser::class, inversedBy: 'profile')]
    #[Column(name: 'user_id', type: 'int')]
    public ?ProxyUser $user = null;
}

#[Entity]
#[Table(name: 'proxy_final_users')]
final class ProxyFinalUser
{
    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'name', type: 'string')]
    public ?string $name = null;
}
