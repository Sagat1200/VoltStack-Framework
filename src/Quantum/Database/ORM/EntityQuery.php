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
     * @var array<string, array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata}>
     */
    private array $joinedAssociations = [];

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
        if ($this->preloadedAssociations !== [] || $this->projectionSelections !== [] || $this->managedPartialSelections !== [] || $this->joinedAssociations !== []) {
            throw new RuntimeException(sprintf(
                'Cannot combine [%s::partial()] with preload, projection, or joined-association mode on [%s]; partial hydration is a separate query mode.',
                self::class,
                $this->metadata->className,
            ));
        }

        $columns = [];
        foreach ($fields as $field) {
            $normalized = trim($field);
            if ($normalized === '') {
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

        $this->partialSelections = array_values($columns);
        $this->query->select(...array_values(array_map(
            static fn(array $selection): string => $selection['column'],
            $this->partialSelections,
        )));

        return $this;
    }

    public function partialManaged(string ...$fields): self
    {
        if ($this->preloadedAssociations !== [] || $this->projectionSelections !== [] || $this->partialSelections !== [] || $this->joinedAssociations !== []) {
            throw new RuntimeException(sprintf(
                'Cannot combine [%s::partialManaged()] with preload, projection, detached partial mode, or joined-association mode on [%s]; managed partial hydration is a separate query mode.',
                self::class,
                $this->metadata->className,
            ));
        }

        $columns = [];
        foreach ($fields as $field) {
            $normalized = trim($field);
            if ($normalized === '') {
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

        $this->managedPartialSelections = array_values($columns);
        $this->query->select(...array_values(array_map(
            static fn(array $selection): string => $selection['column'],
            $this->managedPartialSelections,
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
            $entities[] = $this->manager->hydratePartial($this->metadata, $row);
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

        return $row !== null ? $this->manager->hydratePartial($this->metadata, $row) : null;
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
            $entities[] = $this->manager->hydrateManagedPartial($this->metadata, $row, $loadedFields);
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

        return $this->manager->hydrateManagedPartial($this->metadata, $row, $loadedFields);
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
        if (! $association->isToOne()) {
            throw new RuntimeException(sprintf(
                'Join support currently only covers to-one associations; [%s::$%s] is to-many.',
                $this->metadata->className,
                $normalizedAssociation,
            ));
        }

        if (isset($this->joinedAssociations[$normalizedAssociation])) {
            return $this;
        }

        $targetMetadata = $this->manager->metadata->for($association->targetEntity);
        $joinAlias = trim($alias ?? $normalizedAssociation);
        if ($joinAlias === '') {
            throw new RuntimeException('Join alias cannot be empty.');
        }

        $this->ensureRootAlias();
        [$leftColumn, $rightColumn] = $this->associationJoinColumns($association, $targetMetadata, $joinAlias);

        if (strtolower($type) === 'left') {
            $this->query->leftJoin($targetMetadata->table, $leftColumn, $rightColumn, alias: $joinAlias);
        } else {
            $this->query->join($targetMetadata->table, $leftColumn, $rightColumn, alias: $joinAlias);
        }

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
