<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Execution\DatabaseResult;
use Quantum\Database\Execution\DatabaseResultType;
use Quantum\Database\Execution\RuntimeBindingSet;

final class DatabaseExecutionPrimitivesTest extends TestCase
{
    public function test_runtime_binding_set_normalizes_boolean_values(): void
    {
        $bindings = new RuntimeBindingSet([
            ':active' => true,
            ':archived' => false,
            ':name' => 'VoltStack',
        ]);

        self::assertSame([
            ':active' => 1,
            ':archived' => 0,
            ':name' => 'VoltStack',
        ], $bindings->normalized());
    }

    public function test_database_result_exposes_rows_and_scalar_helpers(): void
    {
        $result = new DatabaseResult(
            type: DatabaseResultType::Rows,
            rows: [
                ['id' => 1, 'name' => 'VoltStack'],
                ['id' => 2, 'name' => 'Quantum'],
            ],
            affectedRows: 2,
            lastInsertId: 2,
        );

        self::assertSame(['id' => 1, 'name' => 'VoltStack'], $result->first());
        self::assertSame(1, $result->scalar());
        self::assertFalse($result->isEmpty());
    }
}
