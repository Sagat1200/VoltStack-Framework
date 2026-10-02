<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Closure;
use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Query\Builder\SelectQueryBuilder;
use RuntimeException;

final class EntityQuery
{
    public SelectQueryBuilder $query;

    /**
     * @var list<string>
     */
    private array $preloadedAssociations = [];

    /**
     * @var list<array{name:string,column:string,hydrate:Closure}>
     */
    private array $projectionSelections = [];

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

            if ($association->isToMany()) {
                throw new RuntimeException(sprintf(
                    'Cannot query by to-many association [%s::$%s]; query by the owning-side relation on the target entity instead.',
                    $this->metadata->className,
                    $field,
                ));
            }

            if (! $association->isOwningSide()) {
                throw new RuntimeException(sprintf(
                    'Cannot query by inverse association [%s::$%s]; query by the owning-side relation on the target entity instead.',
                    $this->metadata->className,
                    $field,
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

            if ($association->isToMany()) {
                throw new RuntimeException(sprintf(
                    'Cannot order by to-many association [%s::$%s].',
                    $this->metadata->className,
                    $field,
                ));
            }

            if (! $association->isOwningSide()) {
                throw new RuntimeException(sprintf(
                    'Cannot order by inverse association [%s::$%s].',
                    $this->metadata->className,
                    $field,
                ));
            }

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

    public function with(string ...$associations): self
    {
        if ($this->projectionSelections !== []) {
            throw new RuntimeException(sprintf(
                'Cannot combine [%s::with()] with projection mode on [%s]; use entity hydration or scalar/projection hydration, not both in the same query.',
                self::class,
                $this->metadata->className,
            ));
        }

        foreach ($associations as $association) {
            $normalized = trim($association);
            if ($normalized === '') {
                continue;
            }

            if (! $this->metadata->hasAssociation($normalized)) {
                throw new RuntimeException(sprintf(
                    'Cannot preload unknown association [%s::$%s].',
                    $this->metadata->className,
                    $normalized,
                ));
            }

            if (! in_array($normalized, $this->preloadedAssociations, true)) {
                $this->preloadedAssociations[] = $normalized;
            }
        }

        return $this;
    }

    public function select(string ...$fields): self
    {
        if ($this->preloadedAssociations !== []) {
            throw new RuntimeException(sprintf(
                'Cannot combine [%s::select()] with [%s::with()] on [%s]; projection queries do not hydrate entities or preload associations.',
                self::class,
                self::class,
                $this->metadata->className,
            ));
        }

        $resolved = [];
        foreach ($fields as $field) {
            $normalized = trim($field);
            if ($normalized === '') {
                continue;
            }

            $resolved[] = $this->resolveProjectionSelection($normalized);
        }

        if ($resolved === []) {
            throw new RuntimeException(sprintf(
                'Projection query for [%s] requires at least one selected field.',
                $this->metadata->className,
            ));
        }

        $this->projectionSelections = $resolved;
        $this->query->select(...array_values(array_map(
            static fn(array $selection): string => $selection['column'],
            $resolved,
        )));

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
        $this->assertEntityHydrationAllowed('get');

        $rows = $this->query->get()->rows();
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->manager->hydrateManaged($this->metadata, $row);
        }

        $this->manager->preloadAssociations($entities, $this->preloadedAssociations);

        return $entities;
    }

    public function first(): ?object
    {
        $this->assertEntityHydrationAllowed('first');

        $row = $this->query->first();

        if ($row === null) {
            return null;
        }

        $entity = $this->manager->hydrateManaged($this->metadata, $row);
        $this->manager->preloadAssociations([$entity], $this->preloadedAssociations);

        return $entity;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->projectionSelections === []) {
            throw new RuntimeException(sprintf(
                'Projection rows() for [%s] requires calling select(...) first.',
                $this->metadata->className,
            ));
        }

        return array_map(
            fn(array $row): array => $this->hydrateProjectionRow($row),
            $this->query->get()->rows(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstRow(): ?array
    {
        if ($this->projectionSelections === []) {
            throw new RuntimeException(sprintf(
                'Projection firstRow() for [%s] requires calling select(...) first.',
                $this->metadata->className,
            ));
        }

        $row = $this->query->first();

        return $row !== null ? $this->hydrateProjectionRow($row) : null;
    }

    /**
     * @return list<mixed>
     */
    public function pluck(string $field): array
    {
        $selection = $this->resolveProjectionSelection(trim($field));
        $rows = $this->queryForSelections([$selection])->get()->rows();

        return array_map(
            fn(array $row): mixed => ($selection['hydrate'])($row[$selection['column']] ?? null),
            $rows,
        );
    }

    public function value(string $field): mixed
    {
        $selection = $this->resolveProjectionSelection(trim($field));
        $row = $this->queryForSelections([$selection])->first();

        if ($row === null) {
            return null;
        }

        return ($selection['hydrate'])($row[$selection['column']] ?? null);
    }

    public function count(?string $column = null): int
    {
        return $this->query->count($column);
    }

    private function assertEntityHydrationAllowed(string $method): void
    {
        if ($this->projectionSelections === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot hydrate entities via %s() on a projection query for [%s]; use rows(), firstRow(), pluck(), or value() instead.',
            $method,
            $this->metadata->className,
        ));
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
     * @return array{name:string,column:string,hydrate:Closure}
     */
    private function resolveProjectionSelection(string $field): array
    {
        if ($field === '') {
            throw new RuntimeException('Projection field name cannot be empty.');
        }

        if ($this->metadata->hasAssociation($field)) {
            $association = $this->metadata->association($field);

            if ($association->isToMany()) {
                throw new RuntimeException(sprintf(
                    'Cannot project to-many association [%s::$%s]; project scalar fields or owning to-one identifiers instead.',
                    $this->metadata->className,
                    $field,
                ));
            }

            if (! $association->isOwningSide() || $association->sourceColumn === null) {
                throw new RuntimeException(sprintf(
                    'Cannot project inverse association [%s::$%s]; only owning to-one associations expose a scalar identifier column in projection mode.',
                    $this->metadata->className,
                    $field,
                ));
            }

            $targetMetadata = $this->manager->metadata->for($association->targetEntity);

            return [
                'name' => $field,
                'column' => $association->sourceColumn,
                'hydrate' => static fn(mixed $value): mixed => $value === null || $value === ''
                    ? null
                    : $targetMetadata->canonicalizeIdentifier($value),
            ];
        }

        $embeddedPath = $this->resolveEmbeddedPath($field);
        if ($embeddedPath !== null) {
            [$innerField, $innerColumn] = $embeddedPath;

            return [
                'name' => $field,
                'column' => $innerColumn,
                'hydrate' => static fn(mixed $value): mixed => $innerField->castValue($value),
            ];
        }

        $fieldMetadata = $this->metadata->field($field);

        return [
            'name' => $field,
            'column' => $fieldMetadata->column,
            'hydrate' => static fn(mixed $value): mixed => $fieldMetadata->castValue($value),
        ];
    }

    /**
     * @param list<array{name:string,column:string,hydrate:Closure}> $selections
     */
    private function queryForSelections(array $selections): SelectQueryBuilder
    {
        $query = clone $this->query;
        $query->select(...array_values(array_map(
            static fn(array $selection): string => $selection['column'],
            $selections,
        )));

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateProjectionRow(array $row): array
    {
        $projected = [];

        foreach ($this->projectionSelections as $selection) {
            $projected[$selection['name']] = ($selection['hydrate'])($row[$selection['column']] ?? null);
        }

        return $projected;
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
