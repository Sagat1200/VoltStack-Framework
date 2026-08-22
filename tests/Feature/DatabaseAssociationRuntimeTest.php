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

final class DatabaseAssociationRuntimeTest extends TestCase
{
    private string $basePath;
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-assoc-' . $suffix;
        $this->sqlitePath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'runtime.sqlite';

        mkdir($this->basePath, 0777, true);
        mkdir(dirname($this->sqlitePath), 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_http_runtime_persists_bidirectional_associations_on_scope_end(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/association/create', CreateAssociationGraphController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/association/create'));
        /** @var array<string,mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['scope_ping']);
        self::assertSame(0, $payload['post_count_during_request']);
        self::assertSame($payload['runtime_request_id'], $payload['database_request_id']);

        $connection = $app->make(ConnectionInterface::class);
        $userCount = (int) (($connection->executeQuery('SELECT COUNT(*) AS aggregate_count FROM runtime_assoc_users')->fetchOneAssoc()['aggregate_count'] ?? 0));
        $postRows = $connection->executeQuery('SELECT author_id, slug FROM runtime_assoc_posts ORDER BY slug ASC')->fetchAllAssoc();

        self::assertSame(1, $userCount);
        self::assertCount(2, $postRows);
        self::assertSame('first-post', $postRows[0]['slug'] ?? null);
        self::assertSame('second-post', $postRows[1]['slug'] ?? null);
        self::assertNotNull($postRows[0]['author_id'] ?? null);
        self::assertSame($postRows[0]['author_id'], $postRows[1]['author_id']);
    }

    public function test_http_runtime_can_lazy_load_one_to_many_association_in_fresh_request(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/association/create', CreateAssociationGraphController::class);
        $router->get('/association/latest', ReadLatestAssociationGraphController::class);

        $kernel = $app->make(HttpKernel::class);
        $createResponse = $kernel->handle(Request::create('/association/create'));
        /** @var array<string,mixed> $createPayload */
        $createPayload = json_decode($createResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        $readResponse = $kernel->handle(Request::create('/association/latest'));
        /** @var array<string,mixed> $readPayload */
        $readPayload = json_decode($readResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($readPayload['found_user']);
        self::assertSame('assoc@example.com', $readPayload['email']);
        self::assertSame(['first-post', 'second-post'], $readPayload['post_slugs']);
        self::assertSame(['First Post', 'Second Post'], $readPayload['post_titles']);
        self::assertSame(2, $readPayload['post_count']);
        self::assertTrue($readPayload['scope_ping']);
        self::assertSame($readPayload['runtime_request_id'], $readPayload['database_request_id']);
        self::assertNotSame($createPayload['runtime_request_id'], $readPayload['runtime_request_id']);
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
                        RuntimeAssocUser::class,
                        RuntimeAssocPost::class,
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
            'CREATE TABLE IF NOT EXISTS runtime_assoc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)'
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS runtime_assoc_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NOT NULL, title TEXT NOT NULL, slug TEXT NOT NULL)'
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

final class CreateAssociationGraphController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        $user = new RuntimeAssocUser();
        $user->email = 'assoc@example.com';

        $first = new RuntimeAssocPost();
        $first->title = 'First Post';
        $first->slug = 'first-post';

        $second = new RuntimeAssocPost();
        $second->title = 'Second Post';
        $second->slug = 'second-post';

        $user->posts->add($first);
        $user->posts->add($second);

        $this->em->persist($user);

        return [
            'scope_ping' => \VoltStack\Runtime\Context\RuntimeContext::current()?->get('database.scope_ping', false) ?? false,
            'post_count_during_request' => $this->em->count(RuntimeAssocPost::class),
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

final class ReadLatestAssociationGraphController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MetadataManagerInterface $metadata,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        /** @var RuntimeAssocUser|null $user */
        $user = $this->em->findOneBy(RuntimeAssocUser::class, [], ['id' => 'DESC']);
        if (!$user instanceof RuntimeAssocUser) {
            return [
                'found_user' => false,
                'scope_ping' => \VoltStack\Runtime\Context\RuntimeContext::current()?->get('database.scope_ping', false) ?? false,
                'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
                'database_request_id' => $this->databaseContext->requestId,
            ];
        }

        $meta = $this->metadata->getMetadataFor(RuntimeAssocUser::class);
        $lazyPosts = (new AssociationHydrationLoader())->createLazyCollection(
            $meta->associations['posts'],
            $user,
            $meta,
            $this->em,
        );
        $user->posts = $lazyPosts;

        $postSlugs = [];
        $postTitles = [];
        foreach ($lazyPosts as $post) {
            if (!$post instanceof RuntimeAssocPost) {
                continue;
            }

            $postSlugs[] = $post->slug;
            $postTitles[] = $post->title;
        }

        return [
            'found_user' => true,
            'email' => $user->email,
            'post_count' => count($postSlugs),
            'post_slugs' => $postSlugs,
            'post_titles' => $postTitles,
            'scope_ping' => \VoltStack\Runtime\Context\RuntimeContext::current()?->get('database.scope_ping', false) ?? false,
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

#[ORM\Entity(table: 'runtime_assoc_users')]
final class RuntimeAssocUser
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'email', type: 'string')]
    public string $email;

    /** @var CollectionInterface<int,RuntimeAssocPost> */
    #[ORM\OneToMany(
        targetEntity: RuntimeAssocPost::class,
        mappedBy: 'author',
        cascade: [CascadeKind::All],
        orderBy: ['slug' => 'ASC'],
    )]
    public CollectionInterface $posts;

    public function __construct()
    {
        $this->posts = new ArrayCollection();
    }
}

#[ORM\Entity(table: 'runtime_assoc_posts')]
final class RuntimeAssocPost
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RuntimeAssocUser::class, inversedBy: 'posts', nullable: false)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumn: 'id', nullable: false)]
    public ?RuntimeAssocUser $author = null;

    #[ORM\Column(name: 'title', type: 'string')]
    public string $title;

    #[ORM\Column(name: 'slug', type: 'string')]
    public string $slug;
}
