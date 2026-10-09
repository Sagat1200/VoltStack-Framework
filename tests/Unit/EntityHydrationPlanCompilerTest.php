<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Embedded;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\ManyToOne;
use Quantum\Database\ORM\Attributes\Table;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\ORM\Planning\EntityHydrationPlanCompiler;
use VoltStack\Framework\Application;

final class EntityHydrationPlanCompilerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-entity-hydration-plan-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_compiles_joined_select_columns_for_entity_hydration(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $compiler = new EntityHydrationPlanCompiler();
        $postMetadata = $registry->for(HydrationPlanPost::class);

        $plan = $compiler->compile(
            $postMetadata,
            't0',
            [
                'author' => [
                    'alias' => 'u1',
                    'type' => 'inner',
                    'association' => $postMetadata->association('author'),
                    'targetMetadata' => $registry->for(HydrationPlanUser::class),
                ],
            ],
        );

        self::assertSame(
            [
                't0.*',
                'u1.id AS __orm_join_author_id',
                'u1.name AS __orm_join_author_name',
                'u1.addr_city AS __orm_join_author_addr_city',
                'u1.addr_country AS __orm_join_author_addr_country',
            ],
            $plan->selectColumns(),
        );
    }

    public function test_it_extracts_joined_target_row_from_compiled_result_keys(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $compiler = new EntityHydrationPlanCompiler();
        $postMetadata = $registry->for(HydrationPlanPost::class);

        $plan = $compiler->compile(
            $postMetadata,
            't0',
            [
                'author' => [
                    'alias' => 'u1',
                    'type' => 'inner',
                    'association' => $postMetadata->association('author'),
                    'targetMetadata' => $registry->for(HydrationPlanUser::class),
                ],
            ],
        );

        $row = $plan->extractJoinedTargetRow('author', [
            '__orm_join_author_id' => 7,
            '__orm_join_author_name' => 'Ada',
            '__orm_join_author_addr_city' => 'Madrid',
            '__orm_join_author_addr_country' => 'ES',
        ]);

        self::assertSame([
            'id' => 7,
            'name' => 'Ada',
            'addr_city' => 'Madrid',
            'addr_country' => 'ES',
        ], $row);
    }

    public function test_it_returns_null_when_joined_target_row_has_no_values(): void
    {
        $app = new Application($this->basePath);
        $registry = $app->make(EntityMetadataRegistry::class);
        $compiler = new EntityHydrationPlanCompiler();
        $postMetadata = $registry->for(HydrationPlanPost::class);

        $plan = $compiler->compile(
            $postMetadata,
            't0',
            [
                'author' => [
                    'alias' => 'u1',
                    'type' => 'left',
                    'association' => $postMetadata->association('author'),
                    'targetMetadata' => $registry->for(HydrationPlanUser::class),
                ],
            ],
        );

        self::assertNull($plan->extractJoinedTargetRow('author', [
            '__orm_join_author_id' => null,
            '__orm_join_author_name' => null,
            '__orm_join_author_addr_city' => null,
            '__orm_join_author_addr_country' => null,
        ]));
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

final class HydrationPlanAddress
{
    #[Column]
    public ?string $city = null;

    #[Column]
    public ?string $country = null;
}

#[Entity]
#[Table('hydration_plan_users')]
final class HydrationPlanUser
{
    #[Id]
    #[Column]
    public ?int $id = null;

    #[Column]
    public ?string $name = null;

    #[Embedded(class: HydrationPlanAddress::class, prefix: 'addr_')]
    public ?HydrationPlanAddress $address = null;
}

#[Entity]
#[Table('hydration_plan_posts')]
final class HydrationPlanPost
{
    #[Id]
    #[Column]
    public ?int $id = null;

    #[Column]
    public ?string $title = null;

    #[Column]
    public ?int $authorId = null;

    #[ManyToOne(targetEntity: HydrationPlanUser::class)]
    public ?HydrationPlanUser $author = null;
}
