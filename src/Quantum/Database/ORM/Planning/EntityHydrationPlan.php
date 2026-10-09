<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Planning;

final readonly class EntityHydrationPlan
{
    /**
     * @param list<string> $selectColumns
     * @param array<string, list<array{column:string,resultKey:string}>> $joinedTargetColumns
     */
    public function __construct(
        private array $selectColumns,
        private array $joinedTargetColumns,
    ) {
    }

    /**
     * @return list<string>
     */
    public function selectColumns(): array
    {
        return $this->selectColumns;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function extractJoinedTargetRow(string $associationName, array $row): ?array
    {
        $columns = $this->joinedTargetColumns[$associationName] ?? [];
        if ($columns === []) {
            return null;
        }

        $targetRow = [];
        $hasAnyValue = false;

        foreach ($columns as $column) {
            if (! array_key_exists($column['resultKey'], $row)) {
                continue;
            }

            $targetRow[$column['column']] = $row[$column['resultKey']];
            if ($row[$column['resultKey']] !== null) {
                $hasAnyValue = true;
            }
        }

        return $hasAnyValue ? $targetRow : null;
    }
}
