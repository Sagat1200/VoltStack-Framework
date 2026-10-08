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
use Quantum\Database\ORM\Planning\AssociationFetchPlanCompiler;
use RuntimeException;
use VoltStack\Framework\Application;

final class AssociationFetchPlanCompilerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-association-fetch-plan-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_compiles_fetch_plan_for_explicit_paths_and_joined_roots(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $compiler = new AssociationFetchPlanCompiler($registry);

        $plan = $compiler->compile(
            $registry->for(PlannerUser::class),
            ['posts.author', 'profile.account'],
            ['profile'],
        );

        self::assertSame(['posts.author'], $plan->preloadPaths());
        self::assertSame([
            'profile' => ['account'],
        ], $plan->joinedNestedPaths());
    }

    public function test_it_groups_paths_by_root_for_recursive_preload_batches(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $compiler = new AssociationFetchPlanCompiler($registry);

        $grouped = $compiler->groupPaths(
            $registry->for(PlannerUser::class),
            ['posts.author', 'profile.account', 'profile'],
        );

        self::assertSame([
            'posts' => ['author'],
            'profile' => ['account'],
        ], $grouped);
    }

    public function test_it_rejects_unknown_nested_association_segments(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $compiler = new AssociationFetchPlanCompiler($registry);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot preload unknown association path [posts.missing]');

        $compiler->normalizePath($registry->for(PlannerUser::class), 'posts.missing');
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
#[Table('planner_users')]
final class PlannerUser
{
    #[Id]
    #[Column]
    public ?int $id = null;

    #[OneToOne(targetEntity: PlannerProfile::class, mappedBy: 'user', fetch: 'eager')]
    public ?PlannerProfile $profile = null;

    /** @var list<PlannerPost> */
    #[OneToMany(targetEntity: PlannerPost::class, mappedBy: 'author')]
    public array $posts = [];
}

#[Entity]
#[Table('planner_profiles')]
final class PlannerProfile
{
    #[Id]
    #[Column]
    public ?int $id = null;

    #[Column]
    public ?int $userId = null;

    #[Column]
    public ?int $accountId = null;

    #[OneToOne(targetEntity: PlannerUser::class, inversedBy: 'profile')]
    public ?PlannerUser $user = null;

    #[ManyToOne(targetEntity: PlannerAccount::class)]
    public ?PlannerAccount $account = null;
}

#[Entity]
#[Table('planner_accounts')]
final class PlannerAccount
{
    #[Id]
    #[Column]
    public ?int $id = null;
}

#[Entity]
#[Table('planner_posts')]
final class PlannerPost
{
    #[Id]
    #[Column]
    public ?int $id = null;

    #[Column]
    public ?int $authorId = null;

    #[ManyToOne(targetEntity: PlannerUser::class, inversedBy: 'posts')]
    public ?PlannerUser $author = null;
}
