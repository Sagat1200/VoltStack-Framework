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
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\ORM\Proxy\LazyLoadableEntityTrait;
use Quantum\Database\ORM\Proxy\ProxyInitializationStatus;
use Quantum\Database\Schema\Builder\TableBlueprint;
use Quantum\Config\ConfigRepository;
use Quantum\Http\Request;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class LazyLoadableEntityTraitTest extends TestCase
{
    private string $basePath;
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-lazy-trait-' . uniqid('', true);
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

    public function test_get_on_uninitialized_placeholder_association_triggers_initializeProxy_then_loads_association(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/lazy-trait/placeholder-through', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('lz_t2_publishers', function (TableBlueprint $t): void {
                $t->id();
                $t->string('name');
            }, true);
            $database->schema()->create('lz_t2_books', function (TableBlueprint $t): void {
                $t->id();
                $t->string('title');
                $t->integer('publisher_id');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            $registry->for(LzT2BookWithTrait::class);
            $registry->for(LzT2PublisherWithTrait::class);

            $pub = new LzT2PublisherWithTrait();
            $pub->name = 'MIT Press';
            $manager->persist($pub);
            $manager->flush();

            $book = new LzT2BookWithTrait();
            $book->title = 'Introduction to Algorithms';
            $book->publisher = $pub;
            $manager->persist($book);
            $manager->flush();
            $manager->clear();

            $uow = $this->unitOfWorkFromEntityManager($manager);

            $placeholder = $manager->getReference(LzT2BookWithTrait::class, $book->id);
            self::assertSame(ProxyInitializationStatus::Uninitialized, $uow->proxyStatusOf($placeholder));

            // Access association on placeholder: trait __get -> initializeProxy + loadToOne
            $loadedPublisher = $placeholder->publisher;
            self::assertNotNull($loadedPublisher, 'Association must be materialized on first access');
            self::assertSame($pub->id, $loadedPublisher->id);
            self::assertSame('MIT Press', $loadedPublisher->name);

            // Scalar fields of parent book now hydrated
            self::assertSame('Introduction to Algorithms', $placeholder->title);
            self::assertSame(ProxyInitializationStatus::Initialized, $uow->proxyStatusOf($placeholder));
        } finally {
            $scope->end();
        }
    }

    public function test_get_on_uninitialized_placeholder_scalar_field_triggers_unified_guardrail_exception(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/lazy-trait/placeholder-scalar-guard', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('lz_t2_publishers', function (TableBlueprint $t): void {
                $t->id();
                $t->string('name');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            $registry->for(LzT2PublisherWithTrait::class);

            $pub = new LzT2PublisherWithTrait();
            $pub->name = 'O\'Reilly';
            $manager->persist($pub);
            $manager->flush();
            $manager->clear();

            $placeholder = $manager->getReference(LzT2PublisherWithTrait::class, $pub->id);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('uninitialized proxy placeholder');

            $_ = $placeholder->name;
        } finally {
            $scope->end();
        }
    }

    public function test_get_on_initialized_entity_lazy_association_auto_loads_and_second_access_uses_same_materialized_reference(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/lazy-trait/initialized-lazy-onetoone', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('lz_t3_authors', function (TableBlueprint $t): void {
                $t->id();
                $t->string('name');
            }, true);
            $database->schema()->create('lz_t3_author_bios', function (TableBlueprint $t): void {
                $t->id();
                $t->string('body');
                $t->integer('author_id');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            // Bidirectional OneToOne:
            //   OWNING = LzT3AuthorBioWithTrait::$author (OneToOne inversedBy bio, FK lives in author_bios)
            //   INVERSE = LzT3AuthorWithTrait::$bio (OneToOne mappedBy author)
            // Handoff: INVERSE side first.
            $registry->for(LzT3AuthorWithTrait::class);
            $registry->for(LzT3AuthorBioWithTrait::class);

            $author = new LzT3AuthorWithTrait();
            $author->name = 'Jane Jacobs';
            $manager->persist($author);
            $manager->flush();

            $bio = new LzT3AuthorBioWithTrait();
            $bio->body = 'Known for Death and Life of Great American Cities.';
            $bio->author = $author;
            $manager->persist($bio);
            $manager->flush();
            $manager->clear();

            $loaded = $manager->find(LzT3AuthorWithTrait::class, $author->id);
            self::assertNotNull($loaded);
            self::assertSame('Jane Jacobs', $loaded->name);

            // Before 1st access: default null (lazy, not materialized yet)
            $before = get_object_vars($loaded);
            self::assertNull($before['bio'] ?? null, 'Lazy OneToOne bio must start as null default');

            // 1st access -> trait __get -> loadToOne + materialize property
            $firstBio = $loaded->bio;
            self::assertNotNull($firstBio, 'Bio must be loaded lazily on 1st access');
            self::assertSame($bio->id, $firstBio->id);
            self::assertSame('Known for Death and Life of Great American Cities.', $firstBio->body);

            // 2nd access -> property shadows __get. Same reference = loader not invoked.
            $secondBio = $loaded->bio;
            self::assertSame($firstBio, $secondBio, 'Second access returns same materialized ref without reloading');
        } finally {
            $scope->end();
        }
    }

    public function test_set_on_uninitialized_placeholder_scalar_throws_but_association_write_is_allowed_and_survives(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/lazy-trait/set-guards', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('lz_t2_publishers', function (TableBlueprint $t): void {
                $t->id();
                $t->string('name');
            }, true);
            $database->schema()->create('lz_t2_books', function (TableBlueprint $t): void {
                $t->id();
                $t->string('title');
                $t->integer('publisher_id');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            $registry->for(LzT2BookWithTrait::class);
            $registry->for(LzT2PublisherWithTrait::class);

            $pub = new LzT2PublisherWithTrait();
            $pub->name = 'Wiley';
            $manager->persist($pub);
            $manager->flush();

            $book = new LzT2BookWithTrait();
            $book->title = 'Design Patterns';
            $book->publisher = $pub;
            $manager->persist($book);
            $manager->flush();
            $manager->clear();

            $placeholder = $manager->getReference(LzT2BookWithTrait::class, $book->id);

            // 1) Scalar write on Uninitialized throws unified guardrail
            $caught = false;
            try {
                $placeholder->title = 'Forbidden write';
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'uninitialized proxy placeholder')) {
                    $caught = true;
                }
            }
            self::assertTrue($caught, 'Scalar __set on Uninitialized placeholder must throw unified uninitialized-proxy RuntimeException');

            // 2) Association write on Uninitialized is allowed; user-set value survives
            $fakePublisher = new LzT2PublisherWithTrait();
            $fakePublisher->id = 999;
            $fakePublisher->name = 'Custom Sideloaded';
            $placeholder->publisher = $fakePublisher;

            self::assertSame($fakePublisher, $placeholder->publisher, 'Association __set on Uninitialized must be preserved and fast-path returned');
        } finally {
            $scope->end();
        }
    }

    public function test_orphaned_entitymanager_scope_throws_on_lazy_access_via_weakref_or_detached(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/lazy-trait/orphaned-weakref', 'GET'));

        $placeholder = null;
        $scopeEnded = false;
        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('lz_t3_authors', function (TableBlueprint $t): void {
                $t->id();
                $t->string('name');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            $registry->for(LzT3AuthorWithTrait::class);
            $registry->for(LzT3AuthorBioWithTrait::class);

            $author = new LzT3AuthorWithTrait();
            $author->name = 'Rebecca Solnit';
            $manager->persist($author);
            $manager->flush();
            $manager->clear();

            $placeholder = $manager->getReference(LzT3AuthorWithTrait::class, $author->id);

            // First: explicit detach via clear(). The trait checks UOW::contains() first, so
            // after clear() the entity is "detached" and access should throw (matches message
            // with either 'orphaned' or 'detached'). This is more deterministic than GC cycles.
            $manager->clear();

            $caughtOrphanedOrDetached = false;
            $caughtMessage = '';
            try {
                $_ = $placeholder->name;
            } catch (RuntimeException $e) {
                $caughtMessage = $e->getMessage();
                if (stripos($caughtMessage, 'orphaned') !== false || stripos($caughtMessage, 'detached') !== false) {
                    $caughtOrphanedOrDetached = true;
                }
            }

            self::assertTrue($caughtOrphanedOrDetached, 'Access after clear (detached) or orphaned EM scope must throw. Got: ' . ($caughtMessage ?: 'NO EXCEPTION'));
            $scopeEnded = true;
        } finally {
            if (!$scopeEnded) {
                try { $scope->end(); } catch (\Throwable) {}
            } else {
                try { $scope->end(); } catch (\Throwable) {}
            }
        }
    }

    public function test_final_class_with_trait_works_like_non_final_and_loads_lazy_associations(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/lazy-trait/final-entity-with-trait', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('lz_t6_tags', function (TableBlueprint $t): void {
                $t->id();
                $t->string('label');
            }, true);
            $database->schema()->create('lz_t6_final_articles', function (TableBlueprint $t): void {
                $t->id();
                $t->string('slug');
                $t->integer('primary_tag_id');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            $registry->for(LzT6FinalArticleWithTrait::class);
            $registry->for(LzT6TagEntity::class);

            $tag = new LzT6TagEntity();
            $tag->label = 'urbanism';
            $manager->persist($tag);
            $manager->flush();

            $article = new LzT6FinalArticleWithTrait();
            $article->slug = 'cities-for-people';
            $article->primaryTag = $tag;
            $manager->persist($article);
            $manager->flush();
            $manager->clear();

            // Scenario A) find() + lazy access
            $found = $manager->find(LzT6FinalArticleWithTrait::class, $article->id);
            self::assertNotNull($found);
            self::assertSame('cities-for-people', $found->slug);
            $tagLoaded = $found->primaryTag;
            self::assertNotNull($tagLoaded);
            self::assertSame($tag->id, $tagLoaded->id);
            self::assertSame('urbanism', $tagLoaded->label);

            $manager->clear();

            // Scenario B) getReference placeholder -> hydrate-through + assoc lazy-load
            $ref = $manager->getReference(LzT6FinalArticleWithTrait::class, $article->id);
            $tagThrough = $ref->primaryTag;
            self::assertNotNull($tagThrough, 'Placeholder hydrate-through must populate lazy association on final with trait');
            self::assertSame($tag->id, $tagThrough->id);
            self::assertSame('cities-for-people', $ref->slug);
        } finally {
            $scope->end();
        }
    }

    public function test_entity_without_trait_preserves_backward_compatibility_no_autoload_explicit_loader_required(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/lazy-trait/no-trait-bc', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('plain_t7_users', function (TableBlueprint $t): void {
                $t->id();
                $t->string('name');
                $t->boolean('active');
            }, true);
            $database->schema()->create('plain_t7_posts', function (TableBlueprint $t): void {
                $t->id();
                $t->integer('author_id');
                $t->string('title');
                $t->boolean('published');
            }, true);

            $manager = $database->entityManager();
            $registry = $app->make(EntityMetadataRegistry::class);
            // Bidirectional:
            // INVERSE = PlainT7User::posts (OneToMany mappedBy=author)
            // OWNING = PlainT7Post::author (ManyToOne inversedBy=posts)
            // Handoff: INVERSE side first.
            $registry->for(PlainT7User::class);
            $registry->for(PlainT7Post::class);

            $user = new PlainT7User();
            $user->name = 'Plain POPO User';
            $user->active = true;
            $manager->persist($user);

            for ($i = 1; $i <= 3; $i++) {
                $post = new PlainT7Post();
                $post->title = "Post {$i}";
                $post->published = true;
                $post->author = $user;
                $manager->persist($post);
            }
            $manager->flush();
            $manager->clear();

            $loaded = $manager->find(PlainT7User::class, $user->id);
            self::assertNotNull($loaded);
            self::assertSame('Plain POPO User', $loaded->name);

            // Without trait, collection stays at default empty array -> NO autoload
            self::assertSame([], $loaded->posts, 'Without trait, default collection preserved empty. BC intact.');

            // Explicit public lazy loading still works
            $posts = $manager->loadToMany($loaded, 'posts');
            self::assertCount(3, $posts);
            self::assertCount(3, $loaded->posts, 'After explicit loadToMany the materialized value lives on entity');
        } finally {
            $scope->end();
        }
    }

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

// ======================================================================
// FIXTURES — Unique classnames globally (no collision with other test files)
// Pattern: For every association, SEPARATE the raw FK (with #[Column]) into
// its own property, and KEEP the entity-typed association WITHOUT #[Column].
// This avoids assigning raw int to PHP entity-typed property.
// ======================================================================

// -- Scenario 1/4: Unidirectional ManyToOne Book -> Publisher --
#[Entity]
#[Table(name: 'lz_t2_publishers')]
class LzT2PublisherWithTrait
{
    use LazyLoadableEntityTrait;

    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'name', type: 'string')]
    public ?string $name = null;
}

#[Entity]
#[Table(name: 'lz_t2_books')]
class LzT2BookWithTrait
{
    use LazyLoadableEntityTrait;

    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'title', type: 'string')]
    public ?string $title = null;

    // FK raw int stored here (Column attribute goes HERE)
    #[Column(name: 'publisher_id', type: 'int')]
    public ?int $publisherId = null;

    // Entity reference: NO #[Column] attribute. ManyToOne only.
    #[ManyToOne(targetEntity: LzT2PublisherWithTrait::class, fetch: 'lazy')]
    public ?LzT2PublisherWithTrait $publisher = null;
}

// -- Scenario 3/5: Bidirectional OneToOne Author <-> AuthorBio --
//   OWNING  = LzT3AuthorBioWithTrait (FK author_id lives here) -> inversedBy: bio
//   INVERSE = LzT3AuthorWithTrait -> mappedBy: author
#[Entity]
#[Table(name: 'lz_t3_authors')]
class LzT3AuthorWithTrait
{
    use LazyLoadableEntityTrait;

    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'name', type: 'string')]
    public ?string $name = null;

    // INVERSE side. NO FK. No Column attribute.
    #[OneToOne(targetEntity: LzT3AuthorBioWithTrait::class, mappedBy: 'author', fetch: 'lazy')]
    public ?LzT3AuthorBioWithTrait $bio = null;
}

#[Entity]
#[Table(name: 'lz_t3_author_bios')]
class LzT3AuthorBioWithTrait
{
    use LazyLoadableEntityTrait;

    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'body', type: 'string')]
    public ?string $body = null;

    // FK raw int stored here
    #[Column(name: 'author_id', type: 'int')]
    public ?int $authorId = null;

    // Entity reference: NO #[Column]. OneToOne OWNING (inversedBy points to inverse property).
    #[OneToOne(targetEntity: LzT3AuthorWithTrait::class, inversedBy: 'bio', fetch: 'lazy')]
    public ?LzT3AuthorWithTrait $author = null;
}

// -- Scenario 6: Final entity with trait + unidirectional ManyToOne --
#[Entity]
#[Table(name: 'lz_t6_tags')]
class LzT6TagEntity
{
    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'label', type: 'string')]
    public ?string $label = null;
}

#[Entity]
#[Table(name: 'lz_t6_final_articles')]
final class LzT6FinalArticleWithTrait
{
    use LazyLoadableEntityTrait;

    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'slug', type: 'string')]
    public ?string $slug = null;

    #[Column(name: 'primary_tag_id', type: 'int')]
    public ?int $primaryTagId = null;

    #[ManyToOne(targetEntity: LzT6TagEntity::class, fetch: 'lazy')]
    public ?LzT6TagEntity $primaryTag = null;
}

// -- Scenario 7: BC test POPOs (no trait) bidirectional OneToMany --
//   INVERSE = PlainT7User::posts (OneToMany mappedBy=author)
//   OWNING = PlainT7Post::author (ManyToOne inversedBy=posts)
#[Entity]
#[Table(name: 'plain_t7_users')]
class PlainT7User
{
    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'name', type: 'string')]
    public ?string $name = null;

    #[Column(name: 'active', type: 'bool')]
    public ?bool $active = null;

    // INVERSE side: no FK. NO Column attribute.
    #[OneToMany(targetEntity: PlainT7Post::class, mappedBy: 'author')]
    public array $posts = [];
}

#[Entity]
#[Table(name: 'plain_t7_posts')]
class PlainT7Post
{
    #[Id]
    #[Column(name: 'id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'title', type: 'string')]
    public ?string $title = null;

    #[Column(name: 'published', type: 'bool')]
    public ?bool $published = null;

    // FK raw int stored here
    #[Column(name: 'author_id', type: 'int')]
    public ?int $authorId = null;

    // Entity reference: NO #[Column]. ManyToOne OWNING.
    #[ManyToOne(targetEntity: PlainT7User::class, inversedBy: 'posts')]
    public ?PlainT7User $author = null;
}
