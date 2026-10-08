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
    private const ROOT_ALIAS = 't0';

    public SelectQueryBuilder $query;

    /**
     * @var list<string>
     */
    private array $preloadedAssociations = [];

    /**
     * @var list<array{name:string,select:string,resultKey:string,hydrate:Closure}>
     */
    private array $projectionSelections = [];

    /**
     * @var list<array{name:string,column:string}>
     */
    private array $partialSelections = [];

    /**
     * @var list<array{name:string,column:string}>
     */
    private array $managedPartialSelections = [];

    /**
     * @var array<string, list<array{name:string,column:string,resultKey:string}>>
     */
    private array $partialJoinedSelections = [];

    /**
     * @var array<string, array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata}>
     */
    private array $joinedAssociations = [];

    private ?int $requestedLimit = null;

    private int $requestedOffset = 0;

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

        $joinedField = $this->resolveJoinedFieldPath($field);
        if ($joinedField !== null) {
            [$targetField, $targetColumn] = $joinedField;
            $targetValue = $argc === 2 ? $operatorOrValue : $value;
            $normalized = $targetField->databaseValueFrom($targetValue);

            if ($argc === 2) {
                $this->query->where($targetColumn, $normalized);
            } else {
                $this->query->where($targetColumn, $operatorOrValue, $normalized);
            }

            return $this;
        }

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

            $column = $this->qualifyRootColumn($column);

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
            $innerColumn = $this->qualifyRootColumn($innerColumn);

            if ($argc === 2) {
                $this->query->where($innerColumn, $normalized);
            } else {
                $this->query->where($innerColumn, $operatorOrValue, $normalized);
            }

            return $this;
        }

        $fieldMetadata = $this->metadata->field($field);
        $column = $this->qualifyRootColumn($fieldMetadata->column);

        if ($argc === 2) {
            $this->query->where($column, $fieldMetadata->databaseValueFrom($operatorOrValue));

            return $this;
        }

        $this->query->where($column, $operatorOrValue, $fieldMetadata->databaseValueFrom($value));

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $joinedField = $this->resolveJoinedFieldPath($field);
        if ($joinedField !== null) {
            [, $targetColumn] = $joinedField;
            $this->query->orderBy($targetColumn, $direction);

            return $this;
        }

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

            $this->query->orderBy($this->qualifyRootColumn($association->sourceColumn), $direction);

            return $this;
        }

        $embeddedPath = $this->resolveEmbeddedPath($field);
        if ($embeddedPath !== null) {
            [, $innerColumn] = $embeddedPath;
            $this->query->orderBy($this->qualifyRootColumn($innerColumn), $direction);

            return $this;
        }

        $this->query->orderBy($this->qualifyRootColumn($this->metadata->field($field)->column), $direction);

        return $this;
    }

    public function join(string $association, ?string $alias = null): self
    {
        return $this->addAssociationJoin('inner', $association, $alias);
    }

    public function leftJoin(string $association, ?string $alias = null): self
    {
        return $this->addAssociationJoin('left', $association, $alias);
    }

    public function with(string ...$associations): self
    {
        if ($this->projectionSelections !== [] || $this->partialSelections !== [] || $this->managedPartialSelections !== []) {
            throw new RuntimeException(sprintf(
                'Cannot combine [%s::with()] with projection or partial hydration mode on [%s]; use one query mode at a time.',
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
        if ($this->preloadedAssociations !== [] || $this->partialSelections !== [] || $this->managedPartialSelections !== []) {
            throw new RuntimeException(sprintf(
                'Cannot combine [%s::select()] with [%s::with()] or partial hydration on [%s]; projection queries do not hydrate entities or preload associations.',
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
            static fn(array $selection): string => $selection['select'],
            $resolved,
        )));

        return $this;
    }

    public function partial(string ...$fields): self
    {
        if ($this->preloadedAssociations !== [] || $this->projectionSelections !== [] || $this->managedPartialSelections !== []) {
            throw new RuntimeException(sprintf(
                'Cannot combine [%s::partial()] with preload, projection, or managed partial mode on [%s]; partial hydration is a separate query mode.',
                self::class,
                $this->metadata->className,
            ));
        }

        $this->assertJoinedPartialHydrationAllowed('partial');

        $columns = [];
        $joinedColumns = [];
        foreach ($fields as $field) {
            $normalized = trim($field);
            if ($normalized === '') {
                continue;
            }

            $joinedSelection = $this->resolveJoinedPartialSelection($normalized);
            if ($joinedSelection !== null) {
                $joinedColumns[$joinedSelection['association']][$joinedSelection['column']] = $joinedSelection;
                continue;
            }

            foreach ($this->resolvePartialSelection($normalized) as $selection) {
                $columns[$selection['column']] = $selection;
            }
        }

        $identifierField = $this->metadata->identifier;
        $columns[$identifierField->column] ??= [
            'name' => $identifierField->name,
            'column' => $identifierField->column,
        ];

        $this->partialJoinedSelections = $this->finalizeJoinedPartialSelections($joinedColumns);
        $this->partialSelections = array_values($columns);
        $this->query->select(...$this->partialHydrationSelectColumns($this->partialSelections));

        return $this;
    }

    public function partialManaged(string ...$fields): self
    {
        if ($this->preloadedAssociations !== [] || $this->projectionSelections !== [] || $this->partialSelections !== []) {
            throw new RuntimeException(sprintf(
                'Cannot combine [%s::partialManaged()] with preload, projection, or detached partial mode on [%s]; managed partial hydration is a separate query mode.',
                self::class,
                $this->metadata->className,
            ));
        }

        $this->assertJoinedPartialHydrationAllowed('partialManaged');

        $columns = [];
        $joinedColumns = [];
        foreach ($fields as $field) {
            $normalized = trim($field);
            if ($normalized === '') {
                continue;
            }

            $joinedSelection = $this->resolveJoinedPartialSelection($normalized);
            if ($joinedSelection !== null) {
                $joinedColumns[$joinedSelection['association']][$joinedSelection['column']] = $joinedSelection;
                continue;
            }

            foreach ($this->resolvePartialSelection($normalized) as $selection) {
                $columns[$selection['column']] = $selection;
            }
        }

        $identifierField = $this->metadata->identifier;
        $columns[$identifierField->column] ??= [
            'name' => $identifierField->name,
            'column' => $identifierField->column,
        ];

        $this->partialJoinedSelections = $this->finalizeJoinedPartialSelections($joinedColumns);
        $this->managedPartialSelections = array_values($columns);
        $this->query->select(...$this->partialHydrationSelectColumns($this->managedPartialSelections));

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->requestedLimit = max(0, $limit);
        $this->query->limit($limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->requestedOffset = max(0, $offset);
        $this->query->offset($offset);

        return $this;
    }

    /**
     * @return list<object>
     */
    public function get(): array
    {
        $this->assertEntityHydrationAllowed('get');

        if ($this->hasJoinedToManyAssociations()) {
            $windowIdentifiers = $this->joinedRootIdentifiersForWindow();
            $entities = $windowIdentifiers === null
                ? $this->hydrateJoinedEntityRows($this->queryForJoinedToManyEntityHydration()->get()->rows())
                : $this->hydrateJoinedEntitiesForRootIdentifiers($windowIdentifiers);
            $this->manager->preloadAssociations($entities, $this->associationsToPreload());

            return $entities;
        }

        $rows = $this->queryForEntityHydration()->get()->rows();
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->hydrateEntityRow($row);
        }

        $this->manager->preloadAssociations($entities, $this->associationsToPreload());

        return $entities;
    }

    public function first(): ?object
    {
        $this->assertEntityHydrationAllowed('first');

        if ($this->hasJoinedToManyAssociations()) {
            $entities = $this->hydrateJoinedEntitiesForRootIdentifiers(
                $this->joinedRootIdentifiersForWindow(limit: 1),
            );

            if ($entities === []) {
                return null;
            }

            $entity = $entities[0];
            $this->manager->preloadAssociations([$entity], $this->associationsToPreload());

            return $entity;
        }

        $row = $this->queryForEntityHydration()->first();

        if ($row === null) {
            return null;
        }

        $entity = $this->hydrateEntityRow($row);
        $this->manager->preloadAssociations([$entity], $this->associationsToPreload());

        return $entity;
    }

    /**
     * @return list<object>
     */
    public function getPartial(): array
    {
        if ($this->partialSelections === []) {
            throw new RuntimeException(sprintf(
                'Partial hydration for [%s] requires calling partial(...) first.',
                $this->metadata->className,
            ));
        }

        $entities = [];
        foreach ($this->query->get()->rows() as $row) {
            $entities[] = $this->partialJoinedSelections === []
                ? $this->manager->hydratePartial($this->metadata, $row)
                : $this->hydrateRelationalPartialRow($row, managed: false);
        }

        return $entities;
    }

    public function firstPartial(): ?object
    {
        if ($this->partialSelections === []) {
            throw new RuntimeException(sprintf(
                'Partial hydration for [%s] requires calling partial(...) first.',
                $this->metadata->className,
            ));
        }

        $row = $this->query->first();

        if ($row === null) {
            return null;
        }

        return $this->partialJoinedSelections === []
            ? $this->manager->hydratePartial($this->metadata, $row)
            : $this->hydrateRelationalPartialRow($row, managed: false);
    }

    /**
     * @return list<object>
     */
    public function getPartialManaged(): array
    {
        if ($this->managedPartialSelections === []) {
            throw new RuntimeException(sprintf(
                'Managed partial hydration for [%s] requires calling partialManaged(...) first.',
                $this->metadata->className,
            ));
        }

        $loadedFields = array_values(array_map(
            static fn(array $selection): string => $selection['name'],
            $this->managedPartialSelections,
        ));

        $entities = [];
        foreach ($this->query->get()->rows() as $row) {
            $entities[] = $this->partialJoinedSelections === []
                ? $this->manager->hydrateManagedPartial($this->metadata, $row, $loadedFields)
                : $this->hydrateRelationalPartialRow($row, managed: true);
        }

        return $entities;
    }

    public function firstPartialManaged(): ?object
    {
        if ($this->managedPartialSelections === []) {
            throw new RuntimeException(sprintf(
                'Managed partial hydration for [%s] requires calling partialManaged(...) first.',
                $this->metadata->className,
            ));
        }

        $row = $this->query->first();
        if ($row === null) {
            return null;
        }

        $loadedFields = array_values(array_map(
            static fn(array $selection): string => $selection['name'],
            $this->managedPartialSelections,
        ));

        return $this->partialJoinedSelections === []
            ? $this->manager->hydrateManagedPartial($this->metadata, $row, $loadedFields)
            : $this->hydrateRelationalPartialRow($row, managed: true);
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
            fn(array $row): mixed => ($selection['hydrate'])($row[$selection['resultKey']] ?? null),
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

        return ($selection['hydrate'])($row[$selection['resultKey']] ?? null);
    }

    public function count(?string $column = null): int
    {
        if ($this->hasJoinedToManyAssociations()) {
            $selection = [
                'name' => $this->metadata->identifier->name,
                'select' => $this->qualifyRootColumn($this->metadata->identifier->column) . ' AS __orm_root_count_id',
                'resultKey' => '__orm_root_count_id',
                'hydrate' => static fn(mixed $value): mixed => $value,
            ];

            $identifiers = [];
            $query = $this->query->withoutLimitOffset();
            $query->select($selection['select']);

            foreach ($query->get()->rows() as $row) {
                $value = $row['__orm_root_count_id'] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                $identifiers[(string) $this->metadata->canonicalizeIdentifier($value)] = true;
            }

            return count($identifiers);
        }

        return $this->query->count($column);
    }

    private function assertEntityHydrationAllowed(string $method): void
    {
        if ($this->projectionSelections === [] && $this->partialSelections === [] && $this->managedPartialSelections === []) {
            return;
        }

        $mode = match (true) {
            $this->projectionSelections !== [] => 'a projection',
            $this->managedPartialSelections !== [] => 'a managed-partial-hydration',
            default => 'a partial-hydration',
        };

        throw new RuntimeException(sprintf(
            'Cannot hydrate entities via %s() on %s query for [%s]; use rows()/firstRow()/pluck()/value() for projections, getPartial()/firstPartial() for detached partial hydration, or getPartialManaged()/firstPartialManaged() for managed partial hydration instead.',
            $method,
            $mode,
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
     * @return array{name:string,select:string,resultKey:string,hydrate:Closure}
     */
    private function resolveProjectionSelection(string $field): array
    {
        if ($field === '') {
            throw new RuntimeException('Projection field name cannot be empty.');
        }

        $joinedField = $this->resolveJoinedFieldPath($field);
        if ($joinedField !== null) {
            [$targetField, $targetColumn] = $joinedField;
            $resultKey = $this->projectionResultKey($field);

            return [
                'name' => $field,
                'select' => $targetColumn . ' AS ' . $resultKey,
                'resultKey' => $resultKey,
                'hydrate' => static fn(mixed $value): mixed => $targetField->castValue($value),
            ];
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
            $resultKey = $this->projectionResultKey($field);

            return [
                'name' => $field,
                'select' => $this->qualifyRootColumn($association->sourceColumn) . ' AS ' . $resultKey,
                'resultKey' => $resultKey,
                'hydrate' => static fn(mixed $value): mixed => $value === null || $value === ''
                    ? null
                    : $targetMetadata->canonicalizeIdentifier($value),
            ];
        }

        $embeddedPath = $this->resolveEmbeddedPath($field);
        if ($embeddedPath !== null) {
            [$innerField, $innerColumn] = $embeddedPath;
            $resultKey = $this->projectionResultKey($field);

            return [
                'name' => $field,
                'select' => $this->qualifyRootColumn($innerColumn) . ' AS ' . $resultKey,
                'resultKey' => $resultKey,
                'hydrate' => static fn(mixed $value): mixed => $innerField->castValue($value),
            ];
        }

        $fieldMetadata = $this->metadata->field($field);
        $resultKey = $this->projectionResultKey($field);

        return [
            'name' => $field,
            'select' => $this->qualifyRootColumn($fieldMetadata->column) . ' AS ' . $resultKey,
            'resultKey' => $resultKey,
            'hydrate' => static fn(mixed $value): mixed => $fieldMetadata->castValue($value),
        ];
    }

    /**
     * @return list<array{name:string,column:string}>
     */
    private function resolvePartialSelection(string $field): array
    {
        if ($field === '') {
            throw new RuntimeException('Partial field name cannot be empty.');
        }

        if ($this->metadata->hasAssociation($field)) {
            throw new RuntimeException(sprintf(
                'Cannot partially hydrate association [%s::$%s]; partial entity hydration currently supports scalar fields and embedded paths only.',
                $this->metadata->className,
                $field,
            ));
        }

        $embeddedPath = $this->resolveEmbeddedPath($field);
        if ($embeddedPath !== null) {
            [, $innerColumn] = $embeddedPath;

            return [[
                'name' => $field,
                'column' => $innerColumn,
            ]];
        }

        $fieldMetadata = $this->metadata->field($field);

        return [[
            'name' => $field,
            'column' => $fieldMetadata->column,
        ]];
    }

    /**
     * @return array{association:string,name:string,column:string,resultKey:string}|null
     */
    private function resolveJoinedPartialSelection(string $field): ?array
    {
        if (! str_contains($field, '.')) {
            return null;
        }

        [$associationName, $targetFieldPath] = explode('.', $field, 2);
        if ($this->metadata->hasAssociation($associationName) && ! isset($this->joinedAssociations[$associationName])) {
            throw new RuntimeException(sprintf(
                'Joined partial field path [%s] requires calling join(%s) or leftJoin(%s) first on [%s].',
                $field,
                var_export($associationName, true),
                var_export($associationName, true),
                $this->metadata->className,
            ));
        }

        if (! isset($this->joinedAssociations[$associationName])) {
            return null;
        }

        $join = $this->joinedAssociations[$associationName];
        $association = $join['association'];
        if (! $association->isToOne()) {
            throw new RuntimeException(sprintf(
                'Cannot partially hydrate joined to-many association [%s::$%s]; relational partial hydration V1 supports joined to-one associations only.',
                $this->metadata->className,
                $associationName,
            ));
        }

        $targetMetadata = $join['targetMetadata'];
        if ($targetMetadata->hasAssociation($targetFieldPath)) {
            throw new RuntimeException(sprintf(
                'Cannot partially hydrate nested association path [%s] on [%s]; relational partial hydration V1 supports scalar fields and embedded paths on joined to-one targets only.',
                $field,
                $targetMetadata->className,
            ));
        }

        $embeddedPath = $this->resolveEmbeddedPathForMetadata($targetMetadata, $targetFieldPath);
        if ($embeddedPath !== null) {
            [, $innerColumn] = $embeddedPath;

            return [
                'association' => $associationName,
                'name' => $targetFieldPath,
                'column' => $innerColumn,
                'resultKey' => $this->joinedResultKey($associationName, $innerColumn),
            ];
        }

        if (! $targetMetadata->hasField($targetFieldPath)) {
            throw new RuntimeException(sprintf(
                'Joined partial field path [%s] is not mapped on target entity [%s].',
                $field,
                $targetMetadata->className,
            ));
        }

        $targetField = $targetMetadata->field($targetFieldPath);

        return [
            'association' => $associationName,
            'name' => $targetField->name,
            'column' => $targetField->column,
            'resultKey' => $this->joinedResultKey($associationName, $targetField->column),
        ];
    }

    /**
     * @param list<array{name:string,select:string,resultKey:string,hydrate:Closure}> $selections
     */
    private function queryForSelections(array $selections): SelectQueryBuilder
    {
        $query = clone $this->query;
        $query->select(...array_values(array_map(
            static fn(array $selection): string => $selection['select'],
            $selections,
        )));

        return $query;
    }

    private function queryForEntityHydration(): SelectQueryBuilder
    {
        if ($this->joinedAssociations === []) {
            return $this->query;
        }

        $query = clone $this->query;
        $query->select(...$this->entityHydrationSelectColumns());

        return $query;
    }

    private function queryForJoinedToManyEntityHydration(): SelectQueryBuilder
    {
        $query = $this->query->withoutLimitOffset();
        $query->select(...$this->entityHydrationSelectColumns());

        return $query;
    }

    /**
     * @param list<array{name:string,column:string}> $rootSelections
     * @return list<string>
     */
    private function partialHydrationSelectColumns(array $rootSelections): array
    {
        $columns = [];

        foreach ($rootSelections as $selection) {
            if ($this->joinedAssociations === []) {
                $columns[] = $selection['column'];
                continue;
            }

            $columns[] = sprintf(
                '%s AS %s',
                $this->qualifyRootColumn($selection['column']),
                $selection['column'],
            );
        }

        foreach ($this->partialJoinedSelections as $associationName => $selections) {
            $join = $this->joinedAssociations[$associationName];
            foreach ($selections as $selection) {
                $columns[] = sprintf(
                    '%s.%s AS %s',
                    $join['alias'],
                    $selection['column'],
                    $selection['resultKey'],
                );
            }
        }

        return $columns;
    }

    /**
     * @param list<int|string> $rootIdentifiers
     * @return list<object>
     */
    private function hydrateJoinedEntitiesForRootIdentifiers(array $rootIdentifiers): array
    {
        if ($rootIdentifiers === []) {
            return [];
        }

        $query = $this->queryForJoinedToManyEntityHydration();
        $query->whereIn(
            $this->qualifyRootColumn($this->metadata->identifier->column),
            $rootIdentifiers,
        );

        return $this->orderEntitiesByRootIdentifiers(
            $this->hydrateJoinedEntityRows($query->get()->rows()),
            $rootIdentifiers,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateProjectionRow(array $row): array
    {
        $projected = [];

        foreach ($this->projectionSelections as $selection) {
            $projected[$selection['name']] = ($selection['hydrate'])($row[$selection['resultKey']] ?? null);
        }

        return $projected;
    }

    private function hydrateRelationalPartialRow(array $row, bool $managed): object
    {
        $rootSelections = $managed ? $this->managedPartialSelections : $this->partialSelections;
        $rootLoadedFields = array_values(array_map(
            static fn(array $selection): string => $selection['name'],
            $rootSelections,
        ));

        $rootRow = [];
        foreach ($rootSelections as $selection) {
            if (! array_key_exists($selection['column'], $row)) {
                continue;
            }

            $rootRow[$selection['column']] = $row[$selection['column']];
        }

        $entity = $managed
            ? $this->manager->hydrateManagedPartial($this->metadata, $rootRow, $rootLoadedFields)
            : $this->manager->hydratePartial($this->metadata, $rootRow);

        foreach ($this->partialJoinedSelections as $associationName => $selections) {
            $join = $this->joinedAssociations[$associationName];
            $association = $join['association'];
            $targetMetadata = $join['targetMetadata'];
            $targetRow = $this->extractJoinedTargetRow($join, $row);

            if ($targetRow === null) {
                $this->assignAssociationValue($entity, $association, null);
                continue;
            }

            $targetLoadedFields = array_values(array_map(
                static fn(array $selection): string => $selection['name'],
                $selections,
            ));

            $target = $managed
                ? $this->manager->hydrateManagedPartial($targetMetadata, $targetRow, $targetLoadedFields)
                : $this->manager->hydratePartial($targetMetadata, $targetRow);

            $this->assignAssociationValue($entity, $association, $target);
            $this->linkJoinedReverseAssociation($entity, $association, $target, $targetMetadata);
        }

        return $entity;
    }

    private function hydrateEntityRow(array $row): object
    {
        $entity = $this->manager->hydrateManaged($this->metadata, $row);

        if ($this->joinedAssociations === []) {
            return $entity;
        }

        foreach ($this->joinedAssociations as $join) {
            $association = $join['association'];
            $targetMetadata = $join['targetMetadata'];
            $targetRow = $this->extractJoinedTargetRow($join, $row);

            if ($targetRow === null) {
                $this->assignAssociationValue($entity, $association, null);

                continue;
            }

            $target = $this->manager->hydrateManaged($targetMetadata, $targetRow);
            $this->assignAssociationValue($entity, $association, $target);
            $this->linkJoinedReverseAssociation($entity, $association, $target, $targetMetadata);
        }

        return $entity;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<object>
     */
    private function hydrateJoinedEntityRows(array $rows): array
    {
        $entitiesById = [];
        $order = [];
        $seenTargets = [];

        foreach ($rows as $row) {
            $rootIdentifier = $row[$this->metadata->identifier->column] ?? null;
            if ($rootIdentifier === null || $rootIdentifier === '') {
                continue;
            }

            $rootKey = (string) $this->metadata->canonicalizeIdentifier($rootIdentifier);
            if (! isset($entitiesById[$rootKey])) {
                $entity = $this->manager->hydrateManaged($this->metadata, $row);
                $this->initializeJoinedCollections($entity);
                $entitiesById[$rootKey] = $entity;
                $order[] = $rootKey;
            }

            $entity = $entitiesById[$rootKey];

            foreach ($this->joinedAssociations as $join) {
                $association = $join['association'];
                if ($association->isToOne()) {
                    $this->hydrateJoinedToOneAssociation($entity, $join, $row);

                    continue;
                }

                $seenTargets[$rootKey][$association->name] ??= [];
                $this->hydrateJoinedToManyAssociation($entity, $join, $row, $seenTargets[$rootKey][$association->name]);
            }
        }

        $entities = array_values(array_map(
            fn(string $key): object => $entitiesById[$key],
            $order,
        ));

        foreach ($entities as $entity) {
            if ($this->manager->contains($entity)) {
                $this->manager->snapshotCollections($entity);
            }
        }

        return $entities;
    }

    /**
     * @param array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata} $join
     */
    private function hydrateJoinedToOneAssociation(object $entity, array $join, array $row): void
    {
        $association = $join['association'];
        $targetMetadata = $join['targetMetadata'];
        $targetRow = $this->extractJoinedTargetRow($join, $row);

        if ($targetRow === null) {
            $this->assignAssociationValue($entity, $association, null);

            return;
        }

        $target = $this->manager->hydrateManaged($targetMetadata, $targetRow);
        $this->assignAssociationValue($entity, $association, $target);
        $this->linkJoinedReverseAssociation($entity, $association, $target, $targetMetadata);
    }

    /**
     * @param array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata} $join
     * @param array<string, true> $seenTargetIds
     */
    private function hydrateJoinedToManyAssociation(object $entity, array $join, array $row, array &$seenTargetIds): void
    {
        $association = $join['association'];
        $targetMetadata = $join['targetMetadata'];
        $current = $this->readAssociationValue($entity, $association);
        if (! is_array($current)) {
            $current = [];
        }

        $targetRow = $this->extractJoinedTargetRow($join, $row);
        if ($targetRow === null) {
            $this->assignAssociationValue($entity, $association, $current);

            return;
        }

        $target = $this->manager->hydrateManaged($targetMetadata, $targetRow);
        $targetId = $targetMetadata->identifierValue($target);
        if ($targetId === null) {
            throw new RuntimeException(sprintf(
                'Joined target [%s] did not expose an identifier during hydration of [%s::$%s].',
                $targetMetadata->className,
                $this->metadata->className,
                $association->name,
            ));
        }

        $targetKey = (string) $targetMetadata->canonicalizeIdentifier($targetId);
        if (! isset($seenTargetIds[$targetKey])) {
            $current[] = $target;
            $seenTargetIds[$targetKey] = true;
            $this->assignAssociationValue($entity, $association, $current);
        }

        $this->linkJoinedReverseAssociation($entity, $association, $target, $targetMetadata);
    }

    private function addAssociationJoin(string $type, string $associationName, ?string $alias): self
    {
        $normalizedAssociation = trim($associationName);
        if ($normalizedAssociation === '') {
            throw new RuntimeException('Join association name cannot be empty.');
        }

        if ($this->projectionSelections !== [] || $this->partialSelections !== [] || $this->managedPartialSelections !== []) {
            throw new RuntimeException(sprintf(
                'Call join methods before select()/partial()/partialManaged() on [%s].',
                $this->metadata->className,
            ));
        }

        if (! $this->metadata->hasAssociation($normalizedAssociation)) {
            throw new RuntimeException(sprintf(
                'Cannot join unknown association [%s::$%s].',
                $this->metadata->className,
                $normalizedAssociation,
            ));
        }

        $association = $this->metadata->association($normalizedAssociation);
        if (isset($this->joinedAssociations[$normalizedAssociation])) {
            return $this;
        }

        $targetMetadata = $this->manager->metadata->for($association->targetEntity);
        $joinAlias = trim($alias ?? $normalizedAssociation);
        if ($joinAlias === '') {
            throw new RuntimeException('Join alias cannot be empty.');
        }

        $this->ensureRootAlias();
        $this->applyAssociationJoin($type, $association, $targetMetadata, $joinAlias);

        $this->joinedAssociations[$normalizedAssociation] = [
            'alias' => $joinAlias,
            'type' => strtolower($type),
            'association' => $association,
            'targetMetadata' => $targetMetadata,
        ];

        return $this;
    }

    private function ensureRootAlias(): void
    {
        if ($this->joinedAssociations !== []) {
            return;
        }

        $this->query->as(self::ROOT_ALIAS);
        $this->query->select(self::ROOT_ALIAS . '.*');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function associationJoinColumns(EntityAssociationMetadata $association, EntityMetadata $targetMetadata, string $joinAlias): array
    {
        if ($association->isOwningSide()) {
            if ($association->sourceColumn === null || $association->targetColumn === null) {
                throw new RuntimeException(sprintf(
                    'Association [%s::$%s] is missing join column metadata for join translation.',
                    $this->metadata->className,
                    $association->name,
                ));
            }

            return [
                $joinAlias . '.' . $association->targetColumn,
                self::ROOT_ALIAS . '.' . $association->sourceColumn,
            ];
        }

        if ($association->targetColumn === null) {
            throw new RuntimeException(sprintf(
                'Inverse association [%s::$%s] is missing target column metadata for join translation.',
                $this->metadata->className,
                $association->name,
            ));
        }

        return [
            $joinAlias . '.' . $association->targetColumn,
            self::ROOT_ALIAS . '.' . $this->metadata->identifier->column,
        ];
    }

    private function applyAssociationJoin(
        string $type,
        EntityAssociationMetadata $association,
        EntityMetadata $targetMetadata,
        string $joinAlias,
    ): void {
        if ($association->isToOne()) {
            [$leftColumn, $rightColumn] = $this->associationJoinColumns($association, $targetMetadata, $joinAlias);

            if (strtolower($type) === 'left') {
                $this->query->leftJoin($targetMetadata->table, $leftColumn, $rightColumn, alias: $joinAlias);
            } else {
                $this->query->join($targetMetadata->table, $leftColumn, $rightColumn, alias: $joinAlias);
            }

            return;
        }

        if ($association->isOneToMany()) {
            if ($association->targetColumn === null) {
                throw new RuntimeException(sprintf(
                    'Association [%s::$%s] is missing target column metadata for one-to-many join translation.',
                    $this->metadata->className,
                    $association->name,
                ));
            }

            if (strtolower($type) === 'left') {
                $this->query->leftJoin(
                    $targetMetadata->table,
                    $joinAlias . '.' . $association->targetColumn,
                    self::ROOT_ALIAS . '.' . $this->metadata->identifier->column,
                    alias: $joinAlias,
                );
            } else {
                $this->query->join(
                    $targetMetadata->table,
                    $joinAlias . '.' . $association->targetColumn,
                    self::ROOT_ALIAS . '.' . $this->metadata->identifier->column,
                    alias: $joinAlias,
                );
            }

            return;
        }

        if (! $association->usesJoinTable()) {
            throw new RuntimeException(sprintf(
                'ManyToMany association [%s::$%s] is missing JoinTable metadata for join translation.',
                $this->metadata->className,
                $association->name,
            ));
        }

        $joinTableAlias = $joinAlias . '__jt';
        if (strtolower($type) === 'left') {
            $this->query->leftJoin(
                $association->joinTable,
                $joinTableAlias . '.' . $association->joinTableSourceColumn,
                self::ROOT_ALIAS . '.' . $this->metadata->identifier->column,
                alias: $joinTableAlias,
            );
            $this->query->leftJoin(
                $targetMetadata->table,
                $joinAlias . '.' . $targetMetadata->identifier->column,
                $joinTableAlias . '.' . $association->joinTableTargetColumn,
                alias: $joinAlias,
            );
        } else {
            $this->query->join(
                $association->joinTable,
                $joinTableAlias . '.' . $association->joinTableSourceColumn,
                self::ROOT_ALIAS . '.' . $this->metadata->identifier->column,
                alias: $joinTableAlias,
            );
            $this->query->join(
                $targetMetadata->table,
                $joinAlias . '.' . $targetMetadata->identifier->column,
                $joinTableAlias . '.' . $association->joinTableTargetColumn,
                alias: $joinAlias,
            );
        }
    }

    private function qualifyRootColumn(string $column): string
    {
        if ($this->joinedAssociations === []) {
            return $column;
        }

        return self::ROOT_ALIAS . '.' . $column;
    }

    /**
     * @return array{0:\Quantum\Database\ORM\Metadata\EntityFieldMetadata,1:string}|null
     */
    private function resolveJoinedFieldPath(string $field): ?array
    {
        if (! str_contains($field, '.')) {
            return null;
        }

        [$associationName, $targetFieldPath] = explode('.', $field, 2);
        if ($this->metadata->hasAssociation($associationName) && ! isset($this->joinedAssociations[$associationName])) {
            throw new RuntimeException(sprintf(
                'Joined field path [%s] requires calling join(%s) or leftJoin(%s) first on [%s].',
                $field,
                var_export($associationName, true),
                var_export($associationName, true),
                $this->metadata->className,
            ));
        }

        if (! isset($this->joinedAssociations[$associationName])) {
            return null;
        }

        $join = $this->joinedAssociations[$associationName];
        $targetMetadata = $join['targetMetadata'];
        $qualifiedAlias = $join['alias'];

        $embeddedPath = $this->resolveEmbeddedPathForMetadata($targetMetadata, $targetFieldPath);
        if ($embeddedPath !== null) {
            [$innerField, $innerColumn] = $embeddedPath;

            return [$innerField, $qualifiedAlias . '.' . $innerColumn];
        }

        if (! $targetMetadata->hasField($targetFieldPath)) {
            throw new RuntimeException(sprintf(
                'Joined field path [%s] is not mapped on target entity [%s].',
                $field,
                $targetMetadata->className,
            ));
        }

        $targetField = $targetMetadata->field($targetFieldPath);

        return [$targetField, $qualifiedAlias . '.' . $targetField->column];
    }

    /**
     * @return array{0:\Quantum\Database\ORM\Metadata\EntityEmbeddedFieldMetadata,1:string}|null
     */
    private function resolveEmbeddedPathForMetadata(EntityMetadata $metadata, string $field): ?array
    {
        if (! str_contains($field, '.')) {
            return null;
        }

        [$embeddedName, $innerName] = explode('.', $field, 2);
        if (! $metadata->hasEmbedded($embeddedName)) {
            return null;
        }

        $embedded = $metadata->embedded($embeddedName);
        if (! $embedded->hasInnerField($innerName)) {
            return null;
        }

        $innerField = $embedded->innerField($innerName);

        return [$innerField, $innerField->column];
    }

    private function projectionResultKey(string $field): string
    {
        return '__orm_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $field);
    }

    /**
     * @return list<string>
     */
    private function entityHydrationSelectColumns(): array
    {
        $columns = [self::ROOT_ALIAS . '.*'];

        foreach ($this->joinedAssociations as $associationName => $join) {
            $targetMetadata = $join['targetMetadata'];
            $alias = $join['alias'];

            foreach ($targetMetadata->mappedFields() as $field) {
                $columns[] = sprintf(
                    '%s.%s AS %s',
                    $alias,
                    $field->column,
                    $this->joinedResultKey($associationName, $field->column),
                );
            }

            foreach ($targetMetadata->embeddeds() as $embedded) {
                foreach ($embedded->mappedInnerFields() as $innerField) {
                    $columns[] = sprintf(
                        '%s.%s AS %s',
                        $alias,
                        $innerField->column,
                        $this->joinedResultKey($associationName, $innerField->column),
                    );
                }
            }
        }

        return $columns;
    }

    /**
     * @param array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata} $join
     * @return array<string, mixed>|null
     */
    private function extractJoinedTargetRow(array $join, array $row): ?array
    {
        $targetMetadata = $join['targetMetadata'];
        $associationName = $join['association']->name;
        $targetRow = [];
        $hasAnyValue = false;

        foreach ($targetMetadata->mappedFields() as $field) {
            $resultKey = $this->joinedResultKey($associationName, $field->column);
            if (! array_key_exists($resultKey, $row)) {
                continue;
            }

            $targetRow[$field->column] = $row[$resultKey];
            if ($row[$resultKey] !== null) {
                $hasAnyValue = true;
            }
        }

        foreach ($targetMetadata->embeddeds() as $embedded) {
            foreach ($embedded->mappedInnerFields() as $innerField) {
                $resultKey = $this->joinedResultKey($associationName, $innerField->column);
                if (! array_key_exists($resultKey, $row)) {
                    continue;
                }

                $targetRow[$innerField->column] = $row[$resultKey];
                if ($row[$resultKey] !== null) {
                    $hasAnyValue = true;
                }
            }
        }

        return $hasAnyValue ? $targetRow : null;
    }

    private function linkJoinedReverseAssociation(
        object $entity,
        EntityAssociationMetadata $association,
        object $target,
        EntityMetadata $targetMetadata,
    ): void {
        $reverseAssociationName = $association->mappedBy ?? $association->inversedBy;
        if ($reverseAssociationName === null || ! $targetMetadata->hasAssociation($reverseAssociationName)) {
            return;
        }

        $reverseAssociation = $targetMetadata->association($reverseAssociationName);
        if (! $reverseAssociation->isToOne()) {
            return;
        }

        $this->assignAssociationValue($target, $reverseAssociation, $entity);
    }

    private function hasJoinedToManyAssociations(): bool
    {
        foreach ($this->joinedAssociations as $join) {
            if ($join['association']->isToMany()) {
                return true;
            }
        }

        return false;
    }

    private function assertJoinedPartialHydrationAllowed(string $mode): void
    {
        foreach ($this->joinedAssociations as $join) {
            if (! $join['association']->isToOne()) {
                throw new RuntimeException(sprintf(
                    'Cannot combine [%s::%s()] with joined to-many association [%s::$%s]; relational partial hydration V1 supports joined to-one associations only.',
                    self::class,
                    $mode,
                    $this->metadata->className,
                    $join['association']->name,
                ));
            }
        }
    }

    /**
     * @param array<string, array<string, array{name:string,column:string,resultKey:string}>> $joinedColumns
     * @return array<string, list<array{name:string,column:string,resultKey:string}>>
     */
    private function finalizeJoinedPartialSelections(array $joinedColumns): array
    {
        foreach ($joinedColumns as $associationName => &$selections) {
            $join = $this->joinedAssociations[$associationName];
            $identifierField = $join['targetMetadata']->identifier;
            $selections[$identifierField->column] ??= [
                'name' => $identifierField->name,
                'column' => $identifierField->column,
                'resultKey' => $this->joinedResultKey($associationName, $identifierField->column),
            ];
            $selections = array_values($selections);
        }
        unset($selections);

        return $joinedColumns;
    }

    /**
     * @return list<int|string>|null
     */
    private function joinedRootIdentifiersForWindow(?int $limit = null): ?array
    {
        $effectiveLimit = $limit ?? $this->requestedLimit;
        if ($this->requestedOffset === 0 && $effectiveLimit === null) {
            return null;
        }

        if ($effectiveLimit === 0) {
            return [];
        }

        $resultKey = '__orm_root_window_id';
        $query = clone $this->query;
        $query->select($this->qualifyRootColumn($this->metadata->identifier->column) . ' AS ' . $resultKey);
        $query->distinct();

        if ($limit !== null) {
            $query->limit($limit);
        }

        $identifiers = [];
        foreach ($query->get()->rows() as $row) {
            $value = $row[$resultKey] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $identifiers[] = $this->metadata->canonicalizeIdentifier($value);
        }

        return $identifiers;
    }

    /**
     * @param list<object> $entities
     * @param list<int|string> $rootIdentifiers
     * @return list<object>
     */
    private function orderEntitiesByRootIdentifiers(array $entities, array $rootIdentifiers): array
    {
        $entitiesById = [];
        foreach ($entities as $entity) {
            $identifier = $this->metadata->identifierValue($entity);
            if ($identifier === null) {
                continue;
            }

            $entitiesById[(string) $this->metadata->canonicalizeIdentifier($identifier)] = $entity;
        }

        $ordered = [];
        foreach ($rootIdentifiers as $identifier) {
            $key = (string) $this->metadata->canonicalizeIdentifier($identifier);
            if (! isset($entitiesById[$key])) {
                continue;
            }

            $ordered[] = $entitiesById[$key];
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    private function remainingPreloadedAssociations(): array
    {
        if ($this->joinedAssociations === []) {
            return $this->preloadedAssociations;
        }

        return array_values(array_filter(
            $this->preloadedAssociations,
            fn(string $association): bool => ! isset($this->joinedAssociations[$association]),
        ));
    }

    /**
     * @return list<string>
     */
    private function associationsToPreload(): array
    {
        $configured = $this->metadata->eagerAssociationNames();
        if ($this->joinedAssociations !== []) {
            $configured = array_values(array_filter(
                $configured,
                fn(string $association): bool => ! isset($this->joinedAssociations[$association]),
            ));
        }

        return array_values(array_unique(array_merge(
            $this->remainingPreloadedAssociations(),
            $configured,
        )));
    }

    private function joinedResultKey(string $associationName, string $column): string
    {
        return '__orm_join_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $associationName . '_' . $column);
    }

    private function initializeJoinedCollections(object $entity): void
    {
        foreach ($this->joinedAssociations as $join) {
            $association = $join['association'];
            if (! $association->isToMany()) {
                continue;
            }

            $this->assignAssociationValue($entity, $association, []);
        }
    }

    private function readAssociationValue(object $entity, EntityAssociationMetadata $association): mixed
    {
        $property = $association->property;
        if (method_exists($property, 'setAccessible')) {
            $property->setAccessible(true);
        }

        if (method_exists($property, 'isInitialized') && ! $property->isInitialized($entity)) {
            return null;
        }

        return $property->getValue($entity);
    }

    private function assignAssociationValue(object $entity, EntityAssociationMetadata $association, mixed $value): void
    {
        $property = $association->property;
        if (method_exists($property, 'setAccessible')) {
            $property->setAccessible(true);
        }

        $property->setValue($entity, $value);
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
