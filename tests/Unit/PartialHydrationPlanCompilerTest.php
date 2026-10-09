<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\ORM\Planning\PartialHydrationPlanCompiler;

final class PartialHydrationPlanCompilerTest extends TestCase
{
    public function test_it_compiles_partial_select_columns_for_root_and_joined_targets(): void
    {
        $compiler = new PartialHydrationPlanCompiler();

        $plan = $compiler->compile(
            't0',
            [
                ['name' => 'id', 'column' => 'id'],
                ['name' => 'title', 'column' => 'title'],
            ],
            [
                'author' => [
                    ['name' => 'id', 'column' => 'id', 'resultKey' => '__orm_join_author_id'],
                    ['name' => 'name', 'column' => 'name', 'resultKey' => '__orm_join_author_name'],
                ],
            ],
            [
                'author' => [
                    'alias' => 'u1',
                    'type' => 'inner',
                    'association' => new \stdClass(),
                    'targetMetadata' => new \stdClass(),
                ],
            ],
        );

        self::assertSame([
            't0.id AS id',
            't0.title AS title',
            'u1.id AS __orm_join_author_id',
            'u1.name AS __orm_join_author_name',
        ], $plan->selectColumns());
    }

    public function test_it_extracts_root_and_joined_target_rows_from_compiled_plan(): void
    {
        $compiler = new PartialHydrationPlanCompiler();

        $plan = $compiler->compile(
            't0',
            [
                ['name' => 'id', 'column' => 'id'],
                ['name' => 'title', 'column' => 'title'],
            ],
            [
                'author' => [
                    ['name' => 'id', 'column' => 'id', 'resultKey' => '__orm_join_author_id'],
                    ['name' => 'name', 'column' => 'name', 'resultKey' => '__orm_join_author_name'],
                ],
            ],
            [
                'author' => [
                    'alias' => 'u1',
                    'type' => 'inner',
                    'association' => new \stdClass(),
                    'targetMetadata' => new \stdClass(),
                ],
            ],
        );

        $row = [
            'id' => 7,
            'title' => 'Alpha',
            '__orm_join_author_id' => 10,
            '__orm_join_author_name' => 'Ada',
        ];

        self::assertSame(['id' => 7, 'title' => 'Alpha'], $plan->extractRootRow($row));
        self::assertSame(['id' => 10, 'name' => 'Ada'], $plan->extractJoinedTargetRow('author', $row));
    }

    public function test_it_exposes_loaded_field_names_and_null_joined_targets_cleanly(): void
    {
        $compiler = new PartialHydrationPlanCompiler();

        $plan = $compiler->compile(
            't0',
            [
                ['name' => 'id', 'column' => 'id'],
                ['name' => 'title', 'column' => 'title'],
            ],
            [
                'author' => [
                    ['name' => 'id', 'column' => 'id', 'resultKey' => '__orm_join_author_id'],
                    ['name' => 'name', 'column' => 'name', 'resultKey' => '__orm_join_author_name'],
                ],
            ],
            [
                'author' => [
                    'alias' => 'u1',
                    'type' => 'left',
                    'association' => new \stdClass(),
                    'targetMetadata' => new \stdClass(),
                ],
            ],
        );

        self::assertSame(['id', 'title'], $plan->rootLoadedFieldNames());
        self::assertSame(['id', 'name'], $plan->joinedTargetLoadedFieldNames('author'));
        self::assertNull($plan->extractJoinedTargetRow('author', [
            '__orm_join_author_id' => null,
            '__orm_join_author_name' => null,
        ]));
    }
}
