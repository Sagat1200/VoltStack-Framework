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
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;
use VoltStack\Framework\Provider\OrmServiceProvider;

final class DatabaseTransactionRuntimeTest extends TestCase
{
    private string $basePath;
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-tx-' . $suffix;
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

    public function test_http_runtime_commits_explicit_transaction_and_reads_graph_in_next_request(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/tx/commit', CommitTransactionGraphController::class);
        $router->get('/tx/latest', ReadLatestTransactionGraphController::class);
        $router->get('/tx/stats', TransactionStatsController::class);

        $kernel = $app->make(HttpKernel::class);

        $commitResponse = $kernel->handle(Request::create('/tx/commit'));
        /** @var array<string,mixed> $commitPayload */
        $commitPayload = json_decode($commitResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $commitResponse->statusCode());
        self::assertTrue($commitPayload['committed']);
        self::assertSame($commitPayload['runtime_request_id'], $commitPayload['database_request_id']);

        $readResponse = $kernel->handle(Request::create('/tx/latest'));
        /** @var array<string,mixed> $readPayload */
        $readPayload = json_decode($readResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($readPayload['found_user']);
        self::assertSame('tx-user-committed', $readPayload['email']);
        self::assertSame(['tx-post-a', 'tx-post-b'], $readPayload['post_slugs']);
        self::assertSame($readPayload['runtime_request_id'], $readPayload['database_request_id']);

        $statsResponse = $kernel->handle(Request::create('/tx/stats'));
        /** @var array<string,int> $statsPayload */
        $statsPayload = json_decode($statsResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $statsPayload['users']);
        self::assertSame(2, $statsPayload['posts']);
    }

    public function test_http_runtime_rolls_back_open_transaction_when_request_ends_without_commit(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/tx/leave-open', LeaveOpenTransactionController::class);
        $router->get('/tx/stats', TransactionStatsController::class);

        $kernel = $app->make(HttpKernel::class);

        $leaveOpenResponse = $kernel->handle(Request::create('/tx/leave-open'));
        /** @var array<string,mixed> $leaveOpenPayload */
        $leaveOpenPayload = json_decode($leaveOpenResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $leaveOpenResponse->statusCode());
        self::assertTrue($leaveOpenPayload['transaction_left_open']);
        self::assertSame(1, $leaveOpenPayload['users_inside_tx']);
        self::assertSame(2, $leaveOpenPayload['posts_inside_tx']);

        $statsResponse = $kernel->handle(Request::create('/tx/stats'));
        /** @var array<string,int> $statsPayload */
        $statsPayload = json_decode($statsResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $statsPayload['users']);
        self::assertSame(0, $statsPayload['posts']);
    }

    public function test_http_runtime_rolls_back_open_transaction_when_controller_throws(): void
    {
        $app = $this->makeDatabaseApp();
        $this->createTables($app);

        $router = $app->make(Router::class);
        $router->get('/tx/fail', FailTransactionController::class);
        $router->get('/tx/stats', TransactionStatsController::class);

        $kernel = $app->make(HttpKernel::class);

        $failResponse = $kernel->handle(Request::create('/tx/fail'));
        self::assertSame(500, $failResponse->statusCode());

        $statsResponse = $kernel->handle(Request::create('/tx/stats'));
        /** @var array<string,int> $statsPayload */
        $statsPayload = json_decode($statsResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $statsPayload['users']);
        self::assertSame(0, $statsPayload['posts']);
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
                        TxUser::class,
                        TxPost::class,
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
            'CREATE TABLE IF NOT EXISTS tx_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)'
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS tx_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, slug TEXT NOT NULL, title TEXT NOT NULL)'
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

final class CommitTransactionGraphController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConnectionInterface $connection,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        $user = $this->buildGraph('tx-user-committed');

        $this->connection->beginTransaction();
        $this->em->persist($user);
        $this->em->flush();
        $this->connection->commit();

        return [
            'committed' => true,
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }

    private function buildGraph(string $email): TxUser
    {
        $user = new TxUser();
        $user->email = $email;

        $postA = new TxPost();
        $postA->slug = 'tx-post-a';
        $postA->title = 'Tx Post A';

        $postB = new TxPost();
        $postB->slug = 'tx-post-b';
        $postB->title = 'Tx Post B';

        $user->posts->add($postA);
        $user->posts->add($postB);

        return $user;
    }
}

final class LeaveOpenTransactionController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConnectionInterface $connection,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        $user = new TxUser();
        $user->email = 'tx-user-open';

        $postA = new TxPost();
        $postA->slug = 'tx-open-a';
        $postA->title = 'Tx Open A';

        $postB = new TxPost();
        $postB->slug = 'tx-open-b';
        $postB->title = 'Tx Open B';

        $user->posts->add($postA);
        $user->posts->add($postB);

        $this->connection->beginTransaction();
        $this->em->persist($user);
        $this->em->flush();

        return [
            'transaction_left_open' => true,
            'users_inside_tx' => $this->countRows('tx_users'),
            'posts_inside_tx' => $this->countRows('tx_posts'),
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }

    private function countRows(string $table): int
    {
        $row = $this->connection
            ->executeQuery('SELECT COUNT(*) AS aggregate_count FROM ' . $this->connection->quoteIdentifier($table))
            ->fetchOneAssoc();

        return (int) ($row['aggregate_count'] ?? 0);
    }
}

final class FailTransactionController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConnectionInterface $connection,
    ) {
    }

    public function __invoke(): never
    {
        $user = new TxUser();
        $user->email = 'tx-user-fail';

        $post = new TxPost();
        $post->slug = 'tx-fail-a';
        $post->title = 'Tx Fail A';
        $user->posts->add($post);

        $this->connection->beginTransaction();
        $this->em->persist($user);
        $this->em->flush();

        throw new RuntimeException('force transaction rollback');
    }
}

final class ReadLatestTransactionGraphController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MetadataManagerInterface $metadata,
        private readonly DatabaseContext $databaseContext,
    ) {
    }

    public function __invoke(): array
    {
        /** @var TxUser|null $user */
        $user = $this->em->findOneBy(TxUser::class, [], ['id' => 'DESC']);
        if (!$user instanceof TxUser) {
            return [
                'found_user' => false,
                'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
                'database_request_id' => $this->databaseContext->requestId,
            ];
        }

        $meta = $this->metadata->getMetadataFor(TxUser::class);
        $posts = (new AssociationHydrationLoader())->createLazyCollection(
            $meta->associations['posts'],
            $user,
            $meta,
            $this->em,
        );
        $user->posts = $posts;

        $slugs = [];
        foreach ($posts as $post) {
            if ($post instanceof TxPost) {
                $slugs[] = $post->slug;
            }
        }

        return [
            'found_user' => true,
            'email' => $user->email,
            'post_slugs' => $slugs,
            'runtime_request_id' => \VoltStack\Runtime\Context\RuntimeContext::current()?->requestId(),
            'database_request_id' => $this->databaseContext->requestId,
        ];
    }
}

final class TransactionStatsController
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function __invoke(): array
    {
        return [
            'users' => $this->countRows('tx_users'),
            'posts' => $this->countRows('tx_posts'),
        ];
    }

    private function countRows(string $table): int
    {
        $row = $this->connection
            ->executeQuery('SELECT COUNT(*) AS aggregate_count FROM ' . $this->connection->quoteIdentifier($table))
            ->fetchOneAssoc();

        return (int) ($row['aggregate_count'] ?? 0);
    }
}

#[ORM\Entity(table: 'tx_users')]
final class TxUser
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'email', type: 'string')]
    public string $email;

    /** @var CollectionInterface<int,TxPost> */
    #[ORM\OneToMany(
        targetEntity: TxPost::class,
        mappedBy: 'author',
        cascade: [CascadeKind::All],
        orphanRemoval: true,
        orderBy: ['slug' => 'ASC'],
    )]
    public CollectionInterface $posts;

    public function __construct()
    {
        $this->posts = new ArrayCollection();
    }
}

#[ORM\Entity(table: 'tx_posts')]
final class TxPost
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TxUser::class, inversedBy: 'posts', joinColumn: 'user_id', referencedColumn: 'id', nullable: false)]
    public ?TxUser $author = null;

    #[ORM\Column(name: 'slug', type: 'string')]
    public string $slug;

    #[ORM\Column(name: 'title', type: 'string')]
    public string $title;
}
