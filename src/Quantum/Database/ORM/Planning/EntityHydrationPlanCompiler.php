<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Planning;

use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;

final readonly class EntityHydrationPlanCompiler
{
    /**
     * @param array<string, array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata}> $joinedAssociations
     */
    public function compile(EntityMetadata $metadata, string $rootAlias, array $joinedAssociations): EntityHydrationPlan
    {
        $selectColumns = [$rootAlias . '.*'];
        $joinedTargetColumns = [];

        foreach ($joinedAssociations as $associationName => $join) {
            $targetMetadata = $join['targetMetadata'];
            $alias = $join['alias'];
            $joinedTargetColumns[$associationName] = [];

            foreach ($targetMetadata->mappedFields() as $field) {
                $resultKey = $this->joinedResultKey($associationName, $field->column);
                $selectColumns[] = sprintf(
                    '%s.%s AS %s',
                    $alias,
                    $field->column,
                    $resultKey,
                );
                $joinedTargetColumns[$associationName][] = [
                    'column' => $field->column,
                    'resultKey' => $resultKey,
                ];
            }

            foreach ($targetMetadata->embeddeds() as $embedded) {
                foreach ($embedded->mappedInnerFields() as $innerField) {
                    $resultKey = $this->joinedResultKey($associationName, $innerField->column);
                    $selectColumns[] = sprintf(
                        '%s.%s AS %s',
                        $alias,
                        $innerField->column,
                        $resultKey,
                    );
                    $joinedTargetColumns[$associationName][] = [
                        'column' => $innerField->column,
                        'resultKey' => $resultKey,
                    ];
                }
            }
        }

        return new EntityHydrationPlan($selectColumns, $joinedTargetColumns);
    }

    private function joinedResultKey(string $associationName, string $column): string
    {
        return '__orm_join_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $associationName . '_' . $column);
    }
}
