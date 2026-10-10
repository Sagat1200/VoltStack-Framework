<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\ManyToOne;
use Quantum\Database\ORM\Attributes\OneToMany;
use Quantum\Database\ORM\Attributes\OneToOne;
use Quantum\Database\ORM\Attributes\Table;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\ORM\Planning\AssociationFetchPlan;
use Quantum\Database\ORM\Planning\CompiledHydrationPlanCache;
use Quantum\Database\ORM\Planning\EntityHydrationPlan;
use Quantum\Database\ORM\Planning\PartialHydrationPlan;
use RuntimeException;
use VoltStack\Framework\Application;

final class CompiledHydrationPlanCacheTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-hydration-plan-cache-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_identical_fetch_plan_inputs_produce_a_cache_hit(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $cache = new CompiledHydrationPlanCache();
        $userMetadata = $registry->for(CacheUser::class);

        $factoryCalls = 0;
        $factory = function () use (&$factoryCalls): AssociationFetchPlan {
            $factoryCalls++;

            return new AssociationFetchPlan(['profile'], []);
        };

        $a = $cache->rememberFetchPlan($userMetadata, ['profile'], [], $factory);
        $b = $cache->rememberFetchPlan($userMetadata, ['profile'], [], $factory);

        self::assertSame(1, $factoryCalls, 'Factory must be called exactly once for identical inputs');
        self::assertSame($a, $b, 'Cache must return the exact same plan instance on hit');

        $stats = $cache->getStats();
        self::assertSame(1, $stats['misses']);
        self::assertSame(1, $stats['hits']);
        self::assertSame(1, $stats['entries']);
    }

    public function test_fetch_plan_keys_are_order_independent_for_explicit_paths_and_joined_roots(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $cache = new CompiledHydrationPlanCache();
        $userMetadata = $registry->for(CacheUser::class);

        $key1 = $cache->fetchPlanKey($userMetadata, ['posts', 'profile'], ['account']);
        $key2 = $cache->fetchPlanKey($userMetadata, ['profile', 'posts'], ['account']);
        $key3 = $cache->fetchPlanKey($userMetadata, ['posts', 'profile'], ['account']);

        self::assertSame($key1, $key2, 'Different path ordering must produce the same fetch-plan key');
        self::assertSame($key1, $key3, 'Identical path ordering must produce the same fetch-plan key');

        $keyDiffJoined = $cache->fetchPlanKey($userMetadata, ['posts', 'profile'], ['tags']);
        self::assertNotSame($key1, $keyDiffJoined, 'Different joined roots must produce different fetch-plan keys');
    }

    public function test_stats_reflect_hits_misses_and_entries_accurately_across_plan_types(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $cache = new CompiledHydrationPlanCache();

        // Warm up registry on inverse side FIRST so the bidirectional link check
        // sees both the owning and inverse side metadata during the same build.
        $userMetadata = $registry->for(CacheUser::class);
        $postMetadata = $registry->for(CachePost::class);
        $authorAssociation = $postMetadata->association('author');
        $authorMetadata = $registry->for($authorAssociation->targetEntity);

        // 1 fetch miss + 1 fetch hit
        $cache->rememberFetchPlan($postMetadata, ['author'], [], static fn() => new AssociationFetchPlan([], []));
        $cache->rememberFetchPlan($postMetadata, ['author'], [], static fn() => new AssociationFetchPlan([], []));

        // 2 hydration misses (different entity class)
        $cache->rememberEntityHydrationPlan($postMetadata, 't0', [], static fn() => new EntityHydrationPlan(['t0.*'], []));
        $joined = [
            'author' => [
                'alias' => 'u1',
                'type' => 'inner',
                'association' => $authorAssociation,
                'targetMetadata' => $authorMetadata,
            ],
        ];
        $cache->rememberEntityHydrationPlan(
            $postMetadata,
            't0',
            $joined,
            static fn() => new EntityHydrationPlan(['t0.*'], []),
        );

        // 1 partial miss
        $cache->rememberPartialHydrationPlan(
            $postMetadata,
            't0',
            [['name' => 'id', 'column' => 'id'], ['name' => 'title', 'column' => 'title']],
            [],
            [],
            static fn() => new PartialHydrationPlan(
                ['id', 'title'],
                [['name' => 'id', 'column' => 'id'], ['name' => 'title', 'column' => 'title']],
                [],
            ),
        );

        $stats = $cache->getStats();
        self::assertSame(4, $stats['misses'], 'Expected 4 unique compilations (fetch/2hydration/partial)');
        self::assertSame(1, $stats['hits'], 'Expected 1 cache hit on fetch plan');
        self::assertSame(4, $stats['entries'], 'Expected 4 distinct cached entries');
        self::assertSame(4, $cache->entryCount());
    }

    public function test_clear_wipes_all_entries_and_leaves_hits_misses_counters(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $cache = new CompiledHydrationPlanCache();

        // Ensure bidirectional associations are fully built before direct CachePost access
        $registry->for(CacheUser::class);
        $postMetadata = $registry->for(CachePost::class);
        $authorAssociation = $postMetadata->association('author');
        $authorMetadata = $registry->for($authorAssociation->targetEntity);

        $cache->rememberFetchPlan($postMetadata, ['author'], [], static fn() => new AssociationFetchPlan([], []));
        $cache->rememberEntityHydrationPlan(
            $postMetadata,
            't0',
            [
                'author' => [
                    'alias' => 'u1',
                    'type' => 'inner',
                    'association' => $authorAssociation,
                    'targetMetadata' => $authorMetadata,
                ],
            ],
            static fn() => new EntityHydrationPlan(['t0.*'], []),
        );

        self::assertSame(2, $cache->entryCount());

        $before = $cache->getStats();
        $cache->clear();

        self::assertSame(0, $cache->entryCount());
        // Lifetime counters must survive clear()
        self::assertSame($before['hits'], $cache->getStats()['hits']);
        self::assertSame($before['misses'], $cache->getStats()['misses']);

        // After clear, re-entry must be a miss again
        $cache->rememberFetchPlan($postMetadata, ['author'], [], static fn() => new AssociationFetchPlan([], []));
        self::assertSame($before['misses'] + 1, $cache->getStats()['misses']);
    }

    public function test_grouped_association_paths_use_deterministic_keys_and_support_cache_hits(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $cache = new CompiledHydrationPlanCache();
        $userMetadata = $registry->for(CacheUser::class);

        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;

            return ['posts' => ['author'], 'profile' => []];
        };

        // Input orders differ but sorted keys must collide
        $a = $cache->rememberGroupedAssociationPaths($userMetadata, ['posts.author', 'profile'], $factory);
        $b = $cache->rememberGroupedAssociationPaths($userMetadata, ['profile', 'posts.author'], $factory);

        self::assertSame(1, $calls, 'Grouped paths with identical sorted content must produce cache hit');
        self::assertSame($a, $b);
        self::assertSame(1, $cache->entryCount());

        // Different entity class => not colliding
        $postMetadata = $registry->for(CachePost::class);
        $cache->rememberGroupedAssociationPaths($postMetadata, ['profile', 'posts.author'], $factory);
        self::assertSame(2, $calls, 'Different entity class must produce a new grouped-paths cache miss');
    }

    public function test_factory_returning_wrong_plan_type_throws_runtime_exception(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $cache = new CompiledHydrationPlanCache();

        // Warm up bidirectional associations through inverse side first
        $registry->for(CacheUser::class);
        $postMetadata = $registry->for(CachePost::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EntityHydrationPlan');

        $cache->rememberEntityHydrationPlan($postMetadata, 't0', [], static fn() => new AssociationFetchPlan([], []));
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

#[Entity]
#[Table('cache_users')]
final class CacheUser
{
    #[Id]
    #[Column]
    public ?int $id = null;

    #[Column]
    public ?string $name = null;

    #[OneToOne(targetEntity: CacheProfile::class, mappedBy: 'user', fetch: 'eager')]
    public ?CacheProfile $profile = null;

    /** @var list<CachePost> */
    #[OneToMany(targetEntity: CachePost::class, mappedBy: 'author')]
    public array $posts = [];
}

#[Entity]
#[Table('cache_profiles')]
final class CacheProfile
{
    #[Id]
    #[Column]
    public ?int $id = null;

    #[Column]
    public ?int $userId = null;

    #[OneToOne(targetEntity: CacheUser::class, inversedBy: 'profile')]
    public ?CacheUser $user = null;
}

#[Entity]
#[Table('cache_posts')]
final class CachePost
{
    #[Id]
    #[Column]
    public ?int $id = null;

    #[Column]
    public ?string $title = null;

    #[Column]
    public ?int $authorId = null;

    #[ManyToOne(targetEntity: CacheUser::class, inversedBy: 'posts')]
    public ?CacheUser $author = null;
}
