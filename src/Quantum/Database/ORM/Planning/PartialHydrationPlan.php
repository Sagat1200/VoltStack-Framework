<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Planning;

final readonly class PartialHydrationPlan
{
    /**
     * @param list<string> $selectColumns
     * @param list<array{name:string,column:string}> $rootSelections
     * @param array<string, list<array{name:string,column:string,resultKey:string}>> $joinedTargetSelections
     */
    public function __construct(
        private array $selectColumns,
        private array $rootSelections,
        private array $joinedTargetSelections,
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
     * @return array<string, mixed>
     */
    public function extractRootRow(array $row): array
    {
        $rootRow = [];

        foreach ($this->rootSelections as $selection) {
            if (! array_key_exists($selection['column'], $row)) {
                continue;
            }

            $rootRow[$selection['column']] = $row[$selection['column']];
        }

        return $rootRow;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function extractJoinedTargetRow(string $associationName, array $row): ?array
    {
        $selections = $this->joinedTargetSelections[$associationName] ?? [];
        if ($selections === []) {
            return null;
        }

        $targetRow = [];
        $hasAnyValue = false;

        foreach ($selections as $selection) {
            if (! array_key_exists($selection['resultKey'], $row)) {
                continue;
            }

            $targetRow[$selection['column']] = $row[$selection['resultKey']];
            if ($row[$selection['resultKey']] !== null) {
                $hasAnyValue = true;
            }
        }

        return $hasAnyValue ? $targetRow : null;
    }

    /**
     * @return list<string>
     */
    public function rootLoadedFieldNames(): array
    {
        return array_values(array_map(
            static fn(array $selection): string => $selection['name'],
            $this->rootSelections,
        ));
    }

    /**
     * @return list<string>
     */
    public function joinedTargetLoadedFieldNames(string $associationName): array
    {
        return array_values(array_map(
            static fn(array $selection): string => $selection['name'],
            $this->joinedTargetSelections[$associationName] ?? [],
        ));
    }
}
