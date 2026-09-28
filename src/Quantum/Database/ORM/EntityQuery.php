<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Query\Builder\SelectQueryBuilder;
use RuntimeException;

final class EntityQuery
{
    public SelectQueryBuilder $query;

    public function __construct(
        private readonly EntityManager $manager,
        private readonly EntityMetadata $metadata,
        DatabaseQueryManager $queries,
    ) {
        $this->query = $queries->table($metadata->table);
    }

    public function where(string $field, mixed $operatorOrValue, mixed $value = null): self
    {
        $argc = func_num_args();

        if ($this->metadata->hasAssociation($field)) {
            $association = $this->metadata->association($field);

            if (! $association->isOwningSide()) {
                throw new RuntimeException(sprintf(
                    'Cannot query by inverse association [%s::$%s]; query by owning-side field [%s] on target entity instead.',
                    $this->metadata->className,
                    $field,
                    $association->targetColumn ?? 'identifier',
                ));
            }

            $targetValue = $argc === 2 ? $operatorOrValue : $value;
            $normalized = $this->normalizeAssociationWhereValue($association, $targetValue);
            $column = $association->sourceColumn;

            if ($column === null) {
                throw new RuntimeException(sprintf(
                    'Association [%s::$%s] has no resolved source column for query translation.',
                    $this->metadata->className,
                    $field,
                ));
            }

            if ($argc === 2) {
                $this->query->where($column, $normalized);
            } else {
                $this->query->where($column, $operatorOrValue, $normalized);
            }

            return $this;
        }

        $embeddedPath = $this->resolveEmbeddedPath($field);
        if ($embeddedPath !== null) {
            [$innerField, $innerColumn] = $embeddedPath;
            $targetValue = $argc === 2 ? $operatorOrValue : $value;
            $normalized = $innerField->databaseValueFrom($targetValue);

            if ($argc === 2) {
                $this->query->where($innerColumn, $normalized);
            } else {
                $this->query->where($innerColumn, $operatorOrValue, $normalized);
            }

            return $this;
        }

        $fieldMetadata = $this->metadata->field($field);
        $column = $fieldMetadata->column;

        if ($argc === 2) {
            $this->query->where($column, $fieldMetadata->databaseValueFrom($operatorOrValue));

            return $this;
        }

        $this->query->where($column, $operatorOrValue, $fieldMetadata->databaseValueFrom($value));

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        if ($this->metadata->hasAssociation($field)) {
            $association = $this->metadata->association($field);

            if ($association->sourceColumn === null) {
                throw new RuntimeException(sprintf(
                    'Association [%s::$%s] has no resolved source column for ordering.',
                    $this->metadata->className,
                    $field,
                ));
            }

            $this->query->orderBy($association->sourceColumn, $direction);

            return $this;
        }

        $embeddedPath = $this->resolveEmbeddedPath($field);
        if ($embeddedPath !== null) {
            [, $innerColumn] = $embeddedPath;
            $this->query->orderBy($innerColumn, $direction);

            return $this;
        }

        $this->query->orderBy($this->metadata->field($field)->column, $direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query->limit($limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->query->offset($offset);

        return $this;
    }

    /**
     * @return list<object>
     */
    public function get(): array
    {
        $rows = $this->query->get()->rows();
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->manager->hydrateManaged($this->metadata, $row);
        }

        return $entities;
    }

    public function first(): ?object
    {
        $row = $this->query->first();

        if ($row === null) {
            return null;
        }

        return $this->manager->hydrateManaged($this->metadata, $row);
    }

    public function count(?string $column = null): int
    {
        return $this->query->count($column);
    }

    private function normalizeAssociationWhereValue(EntityAssociationMetadata $association, mixed $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_object($value)) {
            $targetMetadata = $this->manager->metadata->for($association->targetEntity);
            if (is_a($value, $targetMetadata->className)) {
                $identifier = $targetMetadata->identifierValue($value);
                if ($identifier !== null) {
                    return $identifier;
                }
            }

            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
        }

        throw new RuntimeException(sprintf(
            'Cannot use value of type [%s] for association query; expected int, string, null, or managed [%s].',
            get_debug_type($value),
            $association->targetEntity,
        ));
    }

    /**
     * @return array{0: \Quantum\Database\ORM\Metadata\EntityEmbeddedFieldMetadata, 1: string}|null
     */
    private function resolveEmbeddedPath(string $field): ?array
    {
        if (! str_contains($field, '.')) {
            return null;
        }

        [$embeddedName, $innerName] = explode('.', $field, 2);

        if (! $this->metadata->hasEmbedded($embeddedName)) {
            return null;
        }

        $embedded = $this->metadata->embedded($embeddedName);
        if (! $embedded->hasInnerField($innerName)) {
            return null;
        }

        $innerField = $embedded->innerField($innerName);

        return [$innerField, $innerField->column];
    }
}
