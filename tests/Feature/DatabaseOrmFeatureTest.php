<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Embedded;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\ManyToOne;
use Quantum\Database\ORM\Attributes\OneToMany;
use Quantum\Database\ORM\Attributes\RepositoryFor;
use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\ORM\Contracts\RepositoryFactoryInterface;
use Quantum\Database\ORM\CustomRepositoryRegistry;
use Quantum\Database\ORM\EntityRepository;
use Quantum\Database\ORM\EntityState;
use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
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

    public function test_bidirectional_many_to_one_and_one_to_many_load_and_query_over_sqlite(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/relationships', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_blog_posts', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->boolean('published');
            }, true);
            $database->schema()->create('orm_blog_comments', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('post_id');
                $table->string('body');
            }, true);

            $registry = $app->make(EntityMetadataRegistry::class);
            $postMeta = $registry->for(OrmBlogPost::class);
            $commentMeta = $registry->for(OrmBlogComment::class);

            self::assertTrue($postMeta->hasAssociation('comments'));
            self::assertSame(EntityAssociationMetadata::KIND_ONE_TO_MANY, $postMeta->association('comments')->kind);
            self::assertSame(OrmBlogComment::class, $postMeta->association('comments')->targetEntity);
            self::assertSame('post', $postMeta->association('comments')->mappedBy);
            self::assertTrue($postMeta->association('comments')->isInverseSide());

            self::assertTrue($commentMeta->hasAssociation('post'));
            self::assertSame(EntityAssociationMetadata::KIND_MANY_TO_ONE, $commentMeta->association('post')->kind);
            self::assertSame(OrmBlogPost::class, $commentMeta->association('post')->targetEntity);
            self::assertSame('comments', $commentMeta->association('post')->inversedBy);
            self::assertSame('post_id', $commentMeta->association('post')->sourceColumn);
            self::assertSame('id', $commentMeta->association('post')->targetColumn);
            self::assertTrue($commentMeta->association('post')->isOwningSide());

            $manager = $database->entityManager();

            $post = new OrmBlogPost();
            $post->title = 'Relaciones en VoltStack ORM';
            $post->published = true;
            $manager->persist($post);
            $manager->flush();
            self::assertIsInt($post->id);

            $firstComment = new OrmBlogComment();
            $firstComment->postId = $post->id;
            $firstComment->body = 'Primer comentario';
            $manager->persist($firstComment);

            $secondComment = new OrmBlogComment();
            $secondComment->postId = $post->id;
            $secondComment->body = 'Segundo comentario';
            $manager->persist($secondComment);
            $manager->flush();

            self::assertIsInt($firstComment->id);
            self::assertIsInt($secondComment->id);

            $rawComments = $database->table('orm_blog_comments')
                ->where('post_id', $post->id)
                ->orderBy('id')
                ->get();
            self::assertCount(2, $rawComments->rows());
            self::assertSame('Primer comentario', $rawComments->rows()[0]['body'] ?? null);
            self::assertSame('Segundo comentario', $rawComments->rows()[1]['body'] ?? null);

            $reloadedComment = $manager->find(OrmBlogComment::class, $firstComment->id);
            self::assertInstanceOf(OrmBlogComment::class, $reloadedComment);
            self::assertSame($firstComment, $reloadedComment);
            self::assertSame($post->id, $reloadedComment->postId);

            $loadedPost = $manager->loadToOne($reloadedComment, 'post');
            self::assertSame($post, $loadedPost);
            self::assertSame($post, $reloadedComment->post);
            self::assertSame('Relaciones en VoltStack ORM', $reloadedComment->post->title);

            $byPostObject = $database->repository(OrmBlogComment::class)
                ->findBy(['post' => $post], ['id' => 'asc']);
            self::assertCount(2, $byPostObject);
            self::assertSame($firstComment, $byPostObject[0]);
            self::assertSame($secondComment, $byPostObject[1]);

            $byPostId = $manager->query(OrmBlogComment::class)
                ->where('post', $post->id)
                ->orderBy('body')
                ->get();
            self::assertCount(2, $byPostId);
            self::assertSame($firstComment, $byPostId[0]);
            self::assertSame($secondComment, $byPostId[1]);

            $manager->clear();
            $clearedPost = $manager->find(OrmBlogPost::class, $post->id);
            self::assertNotSame($post, $clearedPost);

            $loadedComments = $manager->loadToMany($clearedPost, 'comments');
            self::assertCount(2, $loadedComments);
            self::assertSame($loadedComments, $clearedPost->comments);
            usort($loadedComments, static fn(OrmBlogComment $a, OrmBlogComment $b): int => $a->id <=> $b->id);
            self::assertSame('Primer comentario', $loadedComments[0]->body);
            self::assertSame('Segundo comentario', $loadedComments[1]->body);

            $sameComments = $manager->loadToMany($clearedPost, 'comments');
            self::assertSame($loadedComments[0], $sameComments[0]);
            self::assertSame($loadedComments[1], $sameComments[1]);

            $commentBackRef = $loadedComments[0]->post;
            self::assertNull($commentBackRef);
            $manager->loadToOne($loadedComments[0], 'post');
            self::assertSame($clearedPost, $loadedComments[0]->post);
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

    public function test_embedded_value_objects_multicolumn_round_trip_and_query_over_sqlite(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/embedded', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_products', function (TableBlueprint $table): void {
                $table->id();
                $table->string('sku');
                $table->integer('price_amount');
                $table->string('price_currency', 3);
                $table->string('dim_width')->nullable();
                $table->string('dim_height')->nullable();
                $table->string('dim_depth')->nullable();
            }, true);

            $registry = $app->make(EntityMetadataRegistry::class);
            $productMeta = $registry->for(OrmProduct::class);

            self::assertTrue($productMeta->hasEmbedded('price'));
            self::assertTrue($productMeta->hasEmbedded('dimensions'));

            $priceEmbedded = $productMeta->embedded('price');
            self::assertSame(OrmMoney::class, $priceEmbedded->embeddableClass);
            self::assertSame('price_', $priceEmbedded->columnPrefix);
            self::assertTrue($priceEmbedded->hasInnerField('amount'));
            self::assertTrue($priceEmbedded->hasInnerField('currency'));
            self::assertSame('price_amount', $priceEmbedded->innerField('amount')->column);
            self::assertSame('price_currency', $priceEmbedded->innerField('currency')->column);

            $dimEmbedded = $productMeta->embedded('dimensions');
            self::assertSame(OrmDimensions::class, $dimEmbedded->embeddableClass);
            self::assertSame('dim_', $dimEmbedded->columnPrefix);
            self::assertSame('dim_width', $dimEmbedded->innerField('width')->column);

            $manager = $database->entityManager();

            $chair = new OrmProduct();
            $chair->sku = 'CHAIR-001';
            $chair->price = new OrmMoney();
            $chair->price->amount = 12999;
            $chair->price->currency = 'USD';
            $chair->dimensions = new OrmDimensions();
            $chair->dimensions->width = '50';
            $chair->dimensions->height = '90';
            $chair->dimensions->depth = '50';
            $manager->persist($chair);

            $desk = new OrmProduct();
            $desk->sku = 'DESK-001';
            $desk->price = new OrmMoney();
            $desk->price->amount = 24999;
            $desk->price->currency = 'USD';
            $manager->persist($desk);

            $freeSticker = new OrmProduct();
            $freeSticker->sku = 'STICKER-FREE';
            $freeSticker->price = new OrmMoney();
            $freeSticker->price->amount = 0;
            $freeSticker->price->currency = 'EUR';
            $manager->persist($freeSticker);
            $manager->flush();

            self::assertIsInt($chair->id);
            self::assertIsInt($desk->id);
            self::assertIsInt($freeSticker->id);

            $rawRow = $database->table('orm_products')
                ->where('sku', 'CHAIR-001')
                ->first();
            self::assertNotNull($rawRow);
            self::assertSame(12999, $rawRow['price_amount'] ?? null);
            self::assertSame('USD', $rawRow['price_currency'] ?? null);
            self::assertSame('50', $rawRow['dim_width'] ?? null);
            self::assertSame('90', $rawRow['dim_height'] ?? null);
            self::assertSame('50', $rawRow['dim_depth'] ?? null);

            $reloadedChair = $manager->find(OrmProduct::class, $chair->id);
            self::assertSame($chair, $reloadedChair);
            self::assertInstanceOf(OrmMoney::class, $reloadedChair->price);
            self::assertSame(12999, $reloadedChair->price->amount);
            self::assertSame('USD', $reloadedChair->price->currency);
            self::assertInstanceOf(OrmDimensions::class, $reloadedChair->dimensions);
            self::assertSame('50', $reloadedChair->dimensions->width);
            self::assertSame('90', $reloadedChair->dimensions->height);
            self::assertSame('50', $reloadedChair->dimensions->depth);

            $reloadedDesk = $manager->find(OrmProduct::class, $desk->id);
            self::assertNotNull($reloadedDesk);
            self::assertNull($reloadedDesk->dimensions);
            self::assertSame(24999, $reloadedDesk->price->amount);

            $byPriceCurrency = $manager->query(OrmProduct::class)
                ->where('price.currency', 'EUR')
                ->get();
            self::assertCount(1, $byPriceCurrency);
            self::assertSame($freeSticker, $byPriceCurrency[0]);

            $byPriceAmountLt = $manager->query(OrmProduct::class)
                ->where('price.amount', '<', 15000)
                ->orderBy('price.amount')
                ->get();
            self::assertCount(2, $byPriceAmountLt);
            self::assertSame($freeSticker, $byPriceAmountLt[0]);
            self::assertSame($chair, $byPriceAmountLt[1]);

            $byDimensionsDepth = $manager->query(OrmProduct::class)
                ->where('dimensions.depth', '50')
                ->orderBy('sku')
                ->get();
            self::assertCount(1, $byDimensionsDepth);
            self::assertSame($chair, $byDimensionsDepth[0]);

            $manager->clear();
            $clearedChair = $manager->find(OrmProduct::class, $chair->id);
            self::assertNotSame($chair, $clearedChair);
            self::assertSame(12999, $clearedChair->price->amount);
            self::assertSame('USD', $clearedChair->price->currency);
            self::assertSame('50', $clearedChair->dimensions->width);

            $clearedChair->price->amount = 13999;
            $clearedChair->dimensions = null;
            $manager->flush();

            $updatedRaw = $database->table('orm_products')
                ->where('id', $chair->id)
                ->first();
            self::assertNotNull($updatedRaw);
            self::assertSame(13999, $updatedRaw['price_amount'] ?? null);
            self::assertNull($updatedRaw['dim_width'] ?? null);
            self::assertNull($updatedRaw['dim_height'] ?? null);
            self::assertNull($updatedRaw['dim_depth'] ?? null);

            $reloadedUpdated = $manager->find(OrmProduct::class, $chair->id);
            self::assertSame($clearedChair, $reloadedUpdated);
            self::assertNull($reloadedUpdated->dimensions);
            self::assertSame(13999, $reloadedUpdated->price->amount);
        } finally {
            $scope->end();
        }
    }

    public function test_repository_factory_with_di_typed_and_ergonomic_helpers(): void
    {
        $app = $this->makeApp();

        $customRepoRegistry = $app->make(CustomRepositoryRegistry::class);
        $customRepoRegistry->register(OrmProductRepository::class);
        self::assertSame(OrmProductRepository::class, $customRepoRegistry->repositoryFor(OrmProduct::class));

        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/orm/repository-factory', 'GET'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('orm_tags', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug');
                $table->boolean('visible');
            }, true);

            $registry = $app->make(EntityMetadataRegistry::class);
            $productMeta = $registry->for(OrmProduct::class);
            self::assertSame(OrmProductRepository::class, $productMeta->repositoryClass);

            $factory = $app->make(RepositoryFactoryInterface::class);
            $userRepo = $factory->repositoryFor(OrmUser::class);
            self::assertInstanceOf(OrmUserRepository::class, $userRepo);
            self::assertSame(OrmUser::class, $userRepo->getEntityClass());
            self::assertSame($database->entityManager(), $userRepo->getEntityManager());

            $dbRepoFactory = $database->repositoryFactory();
            self::assertSame($factory, $dbRepoFactory);

            $tagRepo = $factory->repositoryFor(OrmTag::class);
            self::assertInstanceOf(EntityRepository::class, $tagRepo);

            $php = new OrmTag();
            $php->name = 'PHP';
            $php->slug = 'php';
            $php->visible = true;

            $framework = new OrmTag();
            $framework->name = 'Framework';
            $framework->slug = 'framework';
            $framework->visible = true;

            $hidden = new OrmTag();
            $hidden->name = 'Hidden Draft';
            $hidden->slug = 'hidden-draft';
            $hidden->visible = false;

            $tagRepo->save($php);
            $tagRepo->save($framework);
            $tagRepo->save($hidden, flush: true);
            self::assertNotNull($php->id);
            self::assertNotNull($framework->id);
            self::assertNotNull($hidden->id);

            $rawCount = $database->table('orm_tags')->count();
            self::assertSame(3, $rawCount);

            $allViaCount = $tagRepo->count();
            self::assertSame(3, $allViaCount);

            self::assertTrue($tagRepo->exists(['slug' => 'php']));
            self::assertFalse($tagRepo->exists(['slug' => 'missing']));
            self::assertSame(2, $tagRepo->count(['visible' => true]));
            self::assertSame(1, $tagRepo->count(['visible' => false]));

            $query = $database->entityManager()->query(OrmTag::class);
            self::assertSame(2, $query->where('visible', true)->count());

            $reloadedPhp = $tagRepo->findOneBy(['slug' => 'php']);
            self::assertInstanceOf(OrmTag::class, $reloadedPhp);
            self::assertSame('PHP', $reloadedPhp->name);

            $tagRepo->delete($hidden, flush: true);
            self::assertSame(2, $tagRepo->count());
            self::assertFalse($tagRepo->exists(['slug' => 'hidden-draft']));

            $byName = $tagRepo->findBy([], ['name' => 'asc']);
            self::assertCount(2, $byName);
            self::assertSame('Framework', $byName[0]->name);
            self::assertSame('PHP', $byName[1]->name);

            $customProductRepo = $factory->repositoryFor(OrmProduct::class);
            self::assertInstanceOf(OrmProductRepository::class, $customProductRepo);
            self::assertSame('sku-based-lookup', $customProductRepo->label());
        } finally {
            $scope->end();
        }
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

#[Entity]
final class OrmBlogPost
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $title;

    #[Column]
    public bool $published;

    /** @var list<OrmBlogComment> */
    #[OneToMany(targetEntity: OrmBlogComment::class, mappedBy: 'post')]
    public array $comments = [];
}

#[Entity]
final class OrmBlogComment
{
    #[Id]
    public ?int $id = null;

    #[Column(name: 'post_id')]
    public int $postId;

    #[Column]
    public string $body;

    #[ManyToOne(targetEntity: OrmBlogPost::class, inversedBy: 'comments')]
    public ?OrmBlogPost $post = null;
}

final class OrmMoney
{
    #[Column(type: 'int')]
    public int $amount;

    #[Column]
    public string $currency;
}

final class OrmDimensions
{
    #[Column]
    public string $width;

    #[Column]
    public string $height;

    #[Column]
    public string $depth;
}

#[Entity]
final class OrmProduct
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $sku;

    #[Embedded(class: OrmMoney::class)]
    public ?OrmMoney $price = null;

    #[Embedded(class: OrmDimensions::class, prefix: 'dim_')]
    public ?OrmDimensions $dimensions = null;
}

#[Entity]
final class OrmTag
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name;

    #[Column]
    public string $slug;

    #[Column]
    public bool $visible = false;
}

#[RepositoryFor(OrmProduct::class)]
final class OrmProductRepository extends EntityRepository
{
    public function label(): string
    {
        return 'sku-based-lookup';
    }
}
