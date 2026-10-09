<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Planning;

use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;

final readonly class PartialHydrationPlanCompiler
{
    /**
     * @param list<array{name:string,column:string}> $rootSelections
     * @param array<string, list<array{name:string,column:string,resultKey:string}>> $joinedSelections
     * @param array<string, array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata}> $joinedAssociations
     */
    public function compile(
        string $rootAlias,
        array $rootSelections,
        array $joinedSelections,
        array $joinedAssociations,
    ): PartialHydrationPlan {
        $selectColumns = [];

        foreach ($rootSelections as $selection) {
            if ($joinedAssociations === []) {
                $selectColumns[] = $selection['column'];
                continue;
            }

            $selectColumns[] = sprintf(
                '%s.%s AS %s',
                $rootAlias,
                $selection['column'],
                $selection['column'],
            );
        }

        foreach ($joinedSelections as $associationName => $selections) {
            $join = $joinedAssociations[$associationName] ?? null;
            if ($join === null) {
                continue;
            }

            foreach ($selections as $selection) {
                $selectColumns[] = sprintf(
                    '%s.%s AS %s',
                    $join['alias'],
                    $selection['column'],
                    $selection['resultKey'],
                );
            }
        }

        return new PartialHydrationPlan($selectColumns, $rootSelections, $joinedSelections);
    }
}
