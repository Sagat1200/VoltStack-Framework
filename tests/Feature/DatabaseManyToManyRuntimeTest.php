<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Orm\Association\Collection\ArrayCollection;
use Quantum\Database\Orm\Association\Collection\CollectionInterface;
use Quantum\Database\Orm\Association\Enum\CascadeKind;
use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\Attribute as ORM;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;
use Quantum\Database\Orm\UnitOfWork\Association\AssociationHydrationLoader;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;
use VoltStack\Framework\Provider\OrmServiceProvider;

final class DatabaseManyToManyRuntimeTest extends TestCase
{
    private string $basePath;
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-m2m-' . $suffix;
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

    public function test_http_runtime_persists_many_to_many_graph_and_reads_it_in_fresh_request(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/many/create', CreateManyToManyGraphController::class);
        $router->get('/many/latest', ReadLatestManyToManyGraphController::class);

        $kernel = $app->make(HttpKernel::class);
        $createResponse = $kernel->handle(Request::create('/many/create'));
        /** @var array<string,mixed> $createPayload */
        $createPayload = json_decode($createResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($createPayload['scope_ping']);
        self::assertSame(0, $createPayload['post_count_during_request']);
        self::assertSame($createPayload['runtime_request_id'], $createPayload['database_request_id']);

        $readResponse = $kernel->handle(Request::create('/many/latest'));
        /** @var array<string,mixed> $readPayload */
        $readPayload = json_decode($readResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($readPayload['found_post']);
        self::assertSame('runtime-many-post', $readPayload['slug']);
        self::assertSame(['alpha', 'beta'], $readPayload['tag_names']);
        self::assertSame(2, $readPayload['tag_count']);
        self::assertSame($readPayload['runtime_request_id'], $readPayload['database_request_id']);
        self::assertNotSame($createPayload['runtime_request_id'], $readPayload['runtime_request_id']);

        $connection = $app->make(ConnectionInterface::class);
        $pivotRows = $connection->executeQuery('SELECT post_id, tag_id FROM runtime_post_tags ORDER BY tag_id ASC')->fetchAllAssoc();
        self::assertCount(2, $pivotRows);
    }

    public function test_http_runtime_updates_many_to_many_dirty_collection_across_requests(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/many/create', CreateManyToManyGraphController::class);
        $router->get('/many/latest', ReadLatestManyToManyGraphController::class);
        $router->get('/many/retag', RetagLatestPostController::class);

        $kernel = $app->make(HttpKernel::class);
        $kernel->handle(Request::create('/many/create'));

        $retagResponse = $kernel->handle(Request::create('/many/retag'));
        /** @var array<string,mixed> $retagPayload */
        $retagPayload = json_decode($retagResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['beta', 'gamma'], $retagPayload['tag_names_after_change']);
        self::assertSame($retagPayload['runtime_request_id'], $retagPayload['database_request_id']);

        $readResponse = $kernel->handle(Request::create('/many/latest'));
        /** @var array<string,mixed> $readPayload */
        $readPayload = json_decode($readResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['beta', 'gamma'], $readPayload['tag_names']);
        self::assertSame(2, $readPayload['tag_count']);

        $connection = $app->make(ConnectionInterface::class);
        $tags = $connection->executeQuery('SELECT name FROM runtime_tags ORDER BY name ASC')->fetchAllAssoc();
        $pivotRows = $connection->executeQuery('SELECT post_id, tag_id FROM runtime_post_tags ORDER BY tag_id ASC')->fetchAllAssoc();

        self::assertSame(['alpha', 'beta', 'gamma'], array_map(static fn(array $row): string => (string) $row['name'], $tags));
        self::assertCount(2, $pivotRows);
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
                        RuntimeManyPost::class,
                        RuntimeTag::class,
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
        $connection = $app->make(ConnectionInterface::class);
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS runtime_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, slug TEXT NOT NULL)'
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS runtime_tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS runtime_post_tags (post_id INTEGER NOT NULL, tag_id INTEGER NOT NULL)'
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

final class CreateManyToManyGraphController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        $post = new RuntimeManyPost();
        $post->title = 'Runtime Many Post';
        $post->slug = 'runtime-many-post';

        $alpha = new RuntimeTag();
        $alpha->name = 'alpha';

        $beta = new RuntimeTag();
        $beta->name = 'beta';

        $post->tags->add($alpha);
        $post->tags->add($beta);
        $this->em->persist($post);

        return [
            'scope_ping' => \VoltStack\Runtime\Context\RuntimeContext::current()?->get('database.scope_ping', false) ?? false,
            'post_count_during_request' => $this->em->count(RuntimeManyPost::class),
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

final class ReadLatestManyToManyGraphController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MetadataManagerInterface $metadata,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        /** @var RuntimeManyPost|null $post */
        $post = $this->em->findOneBy(RuntimeManyPost::class, [], ['id' => 'DESC']);
        if (!$post instanceof RuntimeManyPost) {
            return [
                'found_post' => false,
                'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
                'database_request_id' => $this->databaseContext->requestId,
            ];
        }

        $tagNames = $this->loadTagNames($post);

        return [
            'found_post' => true,
            'slug' => $post->slug,
            'tag_count' => count($tagNames),
            'tag_names' => $tagNames,
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }

    /**
     * @return list<string>
     */
    private function loadTagNames(RuntimeManyPost $post): array
    {
        $meta = $this->metadata->getMetadataFor(RuntimeManyPost::class);
        $lazyTags = (new AssociationHydrationLoader())->createLazyCollection(
            $meta->associations['tags'],
            $post,
            $meta,
            $this->em,
        );
        $post->tags = $lazyTags;

        $tagNames = [];
        foreach ($lazyTags as $tag) {
            if ($tag instanceof RuntimeTag) {
                $tagNames[] = $tag->name;
            }
        }

        return $tagNames;
    }
}

final class RetagLatestPostController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MetadataManagerInterface $metadata,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        /** @var RuntimeManyPost|null $post */
        $post = $this->em->findOneBy(RuntimeManyPost::class, [], ['id' => 'DESC']);
        if (!$post instanceof RuntimeManyPost) {
            return [
                'updated' => false,
                'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
                'database_request_id' => $this->databaseContext->requestId,
            ];
        }

        $meta = $this->metadata->getMetadataFor(RuntimeManyPost::class);
        $lazyTags = (new AssociationHydrationLoader())->createLazyCollection(
            $meta->associations['tags'],
            $post,
            $meta,
            $this->em,
        );
        $post->tags = $lazyTags;

        $alpha = null;
        foreach ($lazyTags as $tag) {
            if ($tag instanceof RuntimeTag && $tag->name === 'alpha') {
                $alpha = $tag;
            }
        }

        if ($alpha instanceof RuntimeTag) {
            $lazyTags->remove($alpha);
        }

        $gamma = new RuntimeTag();
        $gamma->name = 'gamma';
        $lazyTags->add($gamma);

        $tagNames = [];
        foreach ($lazyTags as $tag) {
            if ($tag instanceof RuntimeTag) {
                $tagNames[] = $tag->name;
            }
        }
        sort($tagNames);

        return [
            'updated' => true,
            'tag_names_after_change' => $tagNames,
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

#[ORM\Entity(table: 'runtime_posts')]
final class RuntimeManyPost
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'title', type: 'string')]
    public string $title;

    #[ORM\Column(name: 'slug', type: 'string')]
    public string $slug;

    /** @var CollectionInterface<int,RuntimeTag> */
    #[ORM\ManyToMany(
        targetEntity: RuntimeTag::class,
        inversedBy: 'posts',
        joinTable: 'runtime_post_tags',
        joinColumn: 'post_id',
        inverseJoinColumn: 'tag_id',
        cascade: [CascadeKind::All],
        orderBy: ['name' => 'ASC'],
    )]
    public CollectionInterface $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity(table: 'runtime_tags')]
final class RuntimeTag
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string')]
    public string $name;

    /** @var CollectionInterface<int,RuntimeManyPost> */
    #[ORM\ManyToMany(targetEntity: RuntimeManyPost::class, mappedBy: 'tags')]
    public CollectionInterface $posts;

    public function __construct()
    {
        $this->posts = new ArrayCollection();
    }
}
