<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\Contracts\TransactionManagerInterface;
use Quantum\Database\ORM\Contracts\Cascade;
use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use RuntimeException;

final class EntityManager implements EntityManagerInterface
{
    /**
     * @var array<class-string, EntityRepositoryInterface>
     */
    private array $repositories = [];

    public function __construct(
        public readonly EntityMetadataRegistry $metadata,
        private readonly DatabaseQueryManager $queries,
        private readonly IdentityMap $identityMap,
        private readonly UnitOfWork $unitOfWork,
        private readonly TransactionManagerInterface $transactions,
    ) {
    }

    public function find(string $entityClass, mixed $identifier): ?object
    {
        $metadata = $this->metadata->for($entityClass);
        $key = $metadata->keyFor($identifier);
        $managed = $this->identityMap->get($key);

        if ($managed !== null) {
            return $managed;
        }

        $row = $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateManaged($metadata, $row);
    }

    public function hydrateManaged(EntityMetadata $metadata, array $row): object
    {
        if (! array_key_exists($metadata->identifier->column, $row)) {
            throw new RuntimeException(sprintf(
                'Hydration for entity [%s] requires identifier column [%s].',
                $metadata->className,
                $metadata->identifier->column,
            ));
        }

        $key = $metadata->keyFor($row[$metadata->identifier->column]);
        $managed = $this->identityMap->get($key);

        if ($managed !== null) {
            return $managed;
        }

        $entity = $metadata->hydrate($row);
        $this->identityMap->register($key, $entity);
        $this->unitOfWork->registerManaged($entity, $metadata, $key);

        $this->dispatchLifecycle('postLoad', $entity);

        return $entity;
    }

    public function persist(object $entity): void
    {
        $metadata = $this->metadata->for($entity::class);
        $identifier = $metadata->identifierValue($entity);
        $key = $identifier !== null ? $metadata->keyFor($identifier) : null;

        if ($key !== null) {
            $managed = $this->identityMap->get($key);

            if ($managed !== null && $managed !== $entity) {
                throw new RuntimeException(sprintf(
                    'Entity [%s] is already managed with identifier [%s].',
                    $metadata->className,
                    (string) $key->identifier,
                ));
            }

            $this->identityMap->register($key, $entity);
        }

        $this->unitOfWork->persist($entity, $metadata, $key);
    }

    public function remove(object $entity): void
    {
        $this->unitOfWork->remove($entity);
    }

    public function refresh(object $entity): void
    {
        $metadata = $this->metadata->for($entity::class);
        $key = $this->unitOfWork->keyFor($entity);

        if ($key === null) {
            $identifier = $metadata->identifierValue($entity);

            if ($identifier === null) {
                throw new RuntimeException('Cannot refresh an entity without an identifier.');
            }

            $key = $metadata->keyFor($identifier);
        }

        $row = $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->first();

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'Entity [%s] with identifier [%s] no longer exists.',
                $metadata->className,
                (string) $key->identifier,
            ));
        }

        $metadata->hydrate($row, $entity);
        $this->identityMap->register($key, $entity);
        $this->unitOfWork->registerManaged($entity, $metadata, $key);
    }

    public function flush(): void
    {
        // Step 0: Apply CASCADE operations BFS (visited-guard against circular refs).
        $this->applyCascadesBeforeFlush(Cascade::PERSIST);
        $this->applyCascadesBeforeFlush(Cascade::REMOVE);

        // Step 0.1: Collect orphans (inverse OneToMany with orphanRemoval=true).
        $this->collectOrphansForRemoval();

        $operations = function (): void {
            // Insert NEW entities in dependency order so ManyToOne FK references
            // are populated before the owning side is written to the database.
            $this->flushNewEntitiesInDependencyOrder();

            foreach ($this->dirtyManagedEntities() as $entity) {
                $this->flushUpdate($entity);
            }

            foreach ($this->unitOfWork->removedEntities() as $entity) {
                $this->flushDelete($entity);
            }
        };

        if (
            $this->unitOfWork->newEntities() === []
            && $this->dirtyManagedEntities() === []
            && $this->unitOfWork->removedEntities() === []
        ) {
            return;
        }

        $this->transactions->transaction($operations);
    }

    public function clear(): void
    {
        $this->repositories = [];
        $this->identityMap->clear();
        $this->unitOfWork->clear();
    }

    public function contains(object $entity): bool
    {
        return $this->unitOfWork->contains($entity);
    }

    public function state(object $entity): EntityState
    {
        return $this->unitOfWork->state($entity);
    }

    public function repository(string $entityClass): EntityRepositoryInterface
    {
        if (isset($this->repositories[$entityClass])) {
            return $this->repositories[$entityClass];
        }

        $metadata = $this->metadata->for($entityClass);
        $repositoryClass = $metadata->repositoryClass;

        if ($repositoryClass !== null) {
            $repository = new $repositoryClass($this, $metadata);

            if (! $repository instanceof EntityRepositoryInterface) {
                throw new RuntimeException(sprintf(
                    'Repository [%s] must implement [%s].',
                    $repositoryClass,
                    EntityRepositoryInterface::class,
                ));
            }

            return $this->repositories[$entityClass] = $repository;
        }

        return $this->repositories[$entityClass] = new EntityRepository($this, $metadata);
    }

    public function query(string $entityClass): EntityQuery
    {
        return new EntityQuery(
            $this,
            $this->metadata->for($entityClass),
            $this->queries,
        );
    }

    public function loadToOne(object $entity, string $associationName): ?object
    {
        $metadata = $this->metadata->for($entity::class);
        $association = $metadata->association($associationName);

        if (! $association->isOwningSide()) {
            throw new RuntimeException(sprintf(
                'Association [%s::$%s] is not the owning side. Use loadToMany() for inverse ONE_TO_MANY associations.',
                $metadata->className,
                $associationName,
            ));
        }

        $fkValue = $this->associationSourceValue($entity, $association);
        if ($fkValue === null) {
            $this->assignAssociationValue($entity, $association, null);

            return null;
        }

        $target = $this->find($association->targetEntity, $fkValue);
        $this->assignAssociationValue($entity, $association, $target);

        return $target;
    }

    /**
     * @return list<object>
     */
    public function loadToMany(object $entity, string $associationName): array
    {
        $metadata = $this->metadata->for($entity::class);
        $association = $metadata->association($associationName);

        if (! $association->isInverseSide()) {
            throw new RuntimeException(sprintf(
                'Association [%s::$%s] is not the inverse side. Use loadToOne() for owning MANY_TO_ONE associations.',
                $metadata->className,
                $associationName,
            ));
        }

        $identifier = $metadata->identifierValue($entity);
        if ($identifier === null) {
            throw new RuntimeException(sprintf(
                'Cannot load inverse association [%s::$%s] on an entity without an identifier.',
                $metadata->className,
                $associationName,
            ));
        }

        $targetMetadata = $this->metadata->for($association->targetEntity);
        $rows = $this->queries->table($targetMetadata->table)
            ->where($association->targetColumn, $identifier)
            ->get()
            ->rows();

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->hydrateManaged($targetMetadata, $row);
        }

        $this->assignAssociationValue($entity, $association, $results);

        return $results;
    }

    private function flushInsert(object $entity): void
    {
        $metadata = $this->unitOfWork->metadataFor($entity);

        $this->dispatchLifecycle('prePersist', $entity);
        $this->populateManyToOneForeignKeys($entity, $metadata);

        $values = $metadata->extractForWrite(
            $entity,
            includeIdentifier: $metadata->identifierValue($entity) !== null,
        );

        $result = $this->queries->table($metadata->table)->insert($values);
        $identifier = $metadata->identifierValue($entity);

        if ($identifier === null && $result->lastInsertId !== null && $result->lastInsertId !== '') {
            $metadata->assignIdentifier($entity, $result->lastInsertId);
            $identifier = $metadata->identifierValue($entity);
        }

        if ($identifier === null) {
            throw new RuntimeException(sprintf(
                'Inserted entity [%s] did not expose an identifier after flush.',
                $metadata->className,
            ));
        }

        $key = $metadata->keyFor($identifier);
        $this->identityMap->register($key, $entity);
        $this->unitOfWork->synchronize($entity, $key);

        $this->dispatchLifecycle('postPersist', $entity);
    }

    private function flushUpdate(object $entity): void
    {
        $metadata = $this->unitOfWork->metadataFor($entity);
        $key = $this->requireKey($entity);

        $this->populateManyToOneForeignKeys($entity, $metadata);

        $changes = [];
        $current = $metadata->extract($entity, includeIdentifier: false);
        $original = $this->unitOfWork->snapshot($entity);
        unset($original[$metadata->identifier->name]);

        $allFields = array_unique(array_merge(array_keys($current), array_keys($original)));

        foreach ($allFields as $field) {
            $currentHasKey = array_key_exists($field, $current);
            $originalHasKey = array_key_exists($field, $original);
            $currentValue = $currentHasKey ? $current[$field] : null;
            $originalValue = $originalHasKey ? $original[$field] : null;

            if ($currentHasKey && $originalHasKey && $originalValue === $currentValue) {
                continue;
            }

            if ($metadata->hasField($field)) {
                $fieldMeta = $metadata->field($field);
                $changes[$fieldMeta->column] = $fieldMeta->databaseValueFrom($currentValue);

                continue;
            }

            if (str_contains($field, '.')) {
                [$embeddedName, $innerName] = explode('.', $field, 2);

                if ($metadata->hasEmbedded($embeddedName)) {
                    $embedded = $metadata->embedded($embeddedName);
                    if ($embedded->hasInnerField($innerName)) {
                        $innerField = $embedded->innerField($innerName);
                        $changes[$innerField->column] = $innerField->databaseValueFrom($currentValue);

                        continue;
                    }
                }
            }

            throw new RuntimeException(sprintf(
                'Cannot compute update change for entity [%s] unknown field [%s].',
                $metadata->className,
                $field,
            ));
        }

        if ($changes === []) {
            $this->unitOfWork->synchronize($entity, $key);

            return;
        }

        $currentIdentifier = $metadata->identifierValue($entity);

        if ($currentIdentifier !== $key->identifier) {
            throw new RuntimeException(sprintf(
                'Entity [%s] identifier mutation is not supported.',
                $metadata->className,
            ));
        }

        $this->dispatchLifecycle('preUpdate', $entity, [
            'changes' => $changes,
            'currentValues' => $current,
            'originalSnapshot' => $original,
        ]);

        $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->update($changes);

        $this->unitOfWork->synchronize($entity, $key);

        $this->dispatchLifecycle('postUpdate', $entity, [
            'changes' => $changes,
            'currentValues' => $current,
        ]);
    }

    private function flushDelete(object $entity): void
    {
        $metadata = $this->unitOfWork->metadataFor($entity);
        $key = $this->requireKey($entity);

        $this->dispatchLifecycle('preRemove', $entity);

        $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->delete();

        $this->dispatchLifecycle('postRemove', $entity);

        $this->identityMap->remove($key);
        $this->unitOfWork->detach($entity);
    }

    /**
     * @return list<object>
     */
    private function dirtyManagedEntities(): array
    {
        $dirty = [];

        foreach ($this->unitOfWork->managedEntities() as $entity) {
            $metadata = $this->unitOfWork->metadataFor($entity);
            $current = $metadata->extract($entity, includeIdentifier: false);
            $original = $this->unitOfWork->snapshot($entity);
            unset($original[$metadata->identifier->name]);

            if ($current !== $original) {
                $dirty[] = $entity;
            }
        }

        return $dirty;
    }

    private function requireKey(object $entity): EntityKey
    {
        $key = $this->unitOfWork->keyFor($entity);

        if ($key === null) {
            throw new RuntimeException('A persistent entity key is required for this operation.');
        }

        return $key;
    }

    /**
     * Internal lifecycle dispatcher. Invokes, in order:
     *   (1) method-level entity #[Pre* / Post*] attribute callbacks
     *   (2) class-level listeners registered via #[Entity(lifecycleListeners: [X::class])]
     *
     * Callbacks stored on EntityMetadata already receive (entity, em, context) as
     * their argument list, so this routine is a simple linear traversal.
     *
     * @param array<string, mixed> $context
     */
    private function dispatchLifecycle(string $event, object $entity, array $context = []): void
    {
        $metadata = $this->metadata->for($entity::class);

        if (! $metadata->hasCallbacks($event)) {
            return;
        }

        foreach ($metadata->callbacksFor($event) as $callback) {
            $callback($entity, $this, $context);
        }
    }

    /**
     * Apply cascade operations BFS (persist/remove) across the association graph
     * for every entity currently enqueued in the corresponding UoW states.
     *
     * Uses a visited spl_object_id set to prevent infinite recursion when
     * bidirectional associations both declare cascade on the same operation.
     *
     * @param string $operation One of Cascade::PERSIST or Cascade::REMOVE.
     */
    private function applyCascadesBeforeFlush(string $operation): void
    {
        if ($operation !== Cascade::PERSIST && $operation !== Cascade::REMOVE) {
            throw new RuntimeException(sprintf(
                'applyCascadesBeforeFlush only supports %s or %s; got [%s].',
                Cascade::PERSIST,
                Cascade::REMOVE,
                $operation,
            ));
        }

        /** @var list<object> $queue */
        $queue = $operation === Cascade::PERSIST
            ? array_merge($this->unitOfWork->newEntities(), $this->unitOfWork->managedEntities())
            : $this->unitOfWork->removedEntities();

        if ($queue === []) {
            return;
        }

        $visited = [];
        foreach ($queue as $seed) {
            $visited[spl_object_id($seed)] = true;
        }

        while ($queue !== []) {
            $current = array_shift($queue);
            $metadata = $this->metadata->for($current::class);

            foreach ($metadata->associations() as $assoc) {
                $applies = match ($operation) {
                    Cascade::PERSIST => $assoc->cascadesPersist(),
                    Cascade::REMOVE  => $assoc->cascadesRemove(),
                    default          => false,
                };

                if (! $applies) {
                    continue;
                }

                $targets = $this->readAssociationTargets($current, $assoc);
                foreach ($targets as $target) {
                    $targetOid = spl_object_id($target);
                    if (isset($visited[$targetOid])) {
                        continue;
                    }

                    $visited[$targetOid] = true;

                    if ($operation === Cascade::PERSIST) {
                        if ($this->unitOfWork->state($target) === EntityState::Detached) {
                            $this->persist($target);
                        }
                    } else {
                        $state = $this->unitOfWork->state($target);
                        if ($state === EntityState::Managed || $state === EntityState::New) {
                            $this->remove($target);
                        }
                    }

                    $queue[] = $target;
                }
            }
        }
    }

    /**
     * Scan every managed entity whose metadata declares a OneToMany inverse
     * association with orphanRemoval=true, and mark as Removed any collection
     * member that was present in the original snapshot but is no longer in the
     * current collection.
     */
    private function collectOrphansForRemoval(): void
    {
        foreach ($this->unitOfWork->managedEntities() as $entity) {
            $metadata = $this->metadata->for($entity::class);

            foreach ($metadata->associations() as $assoc) {
                if (! $assoc->isOneToMany() || ! $assoc->orphanRemoval) {
                    continue;
                }

                $diff = $this->unitOfWork->collectionDiff($entity, $assoc->name);
                foreach ($diff['removed'] as $orphan) {
                    // V1 constraint: orphanRemoval operates only on Managed
                    // post-flush entities. New (not yet persisted) items are
                    // deliberately skipped to keep behaviour deterministic.
                    if ($this->unitOfWork->state($orphan) !== EntityState::Managed) {
                        continue;
                    }

                    $this->remove($orphan);
                }
            }
        }
    }

    /**
     * Read a single association's target entities regardless of whether it is
     * a MANY_TO_ONE (single object) or ONE_TO_MANY (iterable).
     *
     * @return list<object>
     */
    private function readAssociationTargets(object $entity, EntityAssociationMetadata $assoc): array
    {
        $property = $assoc->property;
        $property->setAccessible(true);
        $raw = $property->getValue($entity);

        if ($raw === null) {
            return [];
        }

        if (is_object($raw) && ! $raw instanceof \Traversable) {
            return [$raw];
        }

        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_object'));
        }

        if ($raw instanceof \Traversable) {
            $out = [];
            foreach ($raw as $item) {
                if (is_object($item)) {
                    $out[] = $item;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * Insert NEW entities in topological order (dependencies first). ManyToOne
     * owning-sides require the referenced (target) entity to have an id before
     * the child INSERT is executed.
     *
     * Algorithm: repeat passes over remaining NEW entities until the queue is
     * empty. In each pass only entities whose ManyToOne target identifiers
     * are already available (assigned or managed-with-id) are inserted. If a
     * full pass produces no forward progress and entities remain → circular.
     */
    private function flushNewEntitiesInDependencyOrder(): void
    {
        $remaining = $this->unitOfWork->newEntities();
        $stallGuard = 0;

        while ($remaining !== []) {
            $stallGuard++;
            $progress = false;
            $next = [];

            foreach ($remaining as $candidate) {
                if ($this->newEntityIsInsertable($candidate)) {
                    $this->flushInsert($candidate);
                    $progress = true;

                    continue;
                }

                $next[] = $candidate;
            }

            if (! $progress) {
                throw new RuntimeException(sprintf(
                    'Unable to resolve NEW entity insert order for [%s] classes. Circular MANY_TO_ONE reference? Remaining: %s.',
                    count($next),
                    implode(', ', array_map(static fn(object $o): string => $o::class, array_slice($next, 0, 5))),
                ));
            }

            $remaining = $next;
        }
    }

    /**
     * Can a NEW entity be safely INSERTed right now? Answer is YES when every
     * ManyToOne owning-side association either:
     *   - has no target object (nullable FK), or
     *   - target object already has a non-null identifier (already inserted
     *     or id assigned by the user ahead of persist).
     */
    private function newEntityIsInsertable(object $entity): bool
    {
        $metadata = $this->unitOfWork->metadataFor($entity);

        foreach ($metadata->associations() as $assoc) {
            if (! $assoc->isManyToOne()) {
                continue;
            }

            $property = $assoc->property;
            $property->setAccessible(true);
            $target = $property->getValue($entity);

            if ($target === null) {
                continue;
            }

            if (! is_object($target)) {
                continue;
            }

            $targetMeta = $this->metadata->for($target::class);
            $targetId = $targetMeta->identifierValue($target);
            if ($targetId === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Before writing an entity via INSERT/UPDATE, make sure every ManyToOne
     * owning side FK field (e.g. `postId` property) is in sync with the
     * actual identifier value of the associated object held in the PHP
     * reference (e.g. `$comment->post->id`).
     *
     * Uses the same source-field naming convention as associationSourceValue.
     */
    private function populateManyToOneForeignKeys(object $entity, EntityMetadata $metadata): void
    {
        foreach ($metadata->associations() as $assoc) {
            if (! $assoc->isManyToOne()) {
                continue;
            }

            $target = $this->readSingleAssociationTarget($assoc, $entity);
            if ($target === null) {
                continue;
            }

            $targetMeta = $this->metadata->for($target::class);
            $targetId = $targetMeta->identifierValue($target);
            if ($targetId === null) {
                continue;
            }

            $this->assignOwningSideForeignKey($entity, $metadata, $assoc, $targetId);
        }
    }

    private function readSingleAssociationTarget(EntityAssociationMetadata $assoc, object $entity): ?object
    {
        $property = $assoc->property;
        $property->setAccessible(true);
        $raw = $property->getValue($entity);

        return is_object($raw) ? $raw : null;
    }

    /**
     * @param int|string $value
     */
    private function assignOwningSideForeignKey(
        object $entity,
        EntityMetadata $metadata,
        EntityAssociationMetadata $assoc,
        mixed $value,
    ): void {
        // Naming priority (mirrors associationSourceValue helper):
        //   1. explicit {sourceField}Id field declared on the entity
        //   2. field whose column matches $assoc->sourceColumn
        //   3. explicit $assoc->sourceField name directly (if exists as field)
        if ($assoc->sourceField !== null && $metadata->hasField($assoc->sourceField . 'Id')) {
            $metadata->field($assoc->sourceField . 'Id')->setValue($entity, $value);

            return;
        }

        if ($assoc->sourceColumn !== null) {
            foreach ($metadata->mappedFields() as $field) {
                if ($field->column === $assoc->sourceColumn) {
                    $field->setValue($entity, $value);

                    return;
                }
            }
        }

        if ($assoc->sourceField !== null && $metadata->hasField($assoc->sourceField)) {
            $metadata->field($assoc->sourceField)->setValue($entity, $value);
        }
    }

    private function associationSourceValue(object $entity, EntityAssociationMetadata $association): int|string|null
    {
        $metadata = $this->metadata->for($entity::class);

        if ($association->sourceField === null || ! $metadata->hasField($association->sourceField . 'Id')) {
            $candidate = null;
            foreach ($metadata->mappedFields() as $field) {
                if ($association->sourceColumn !== null && $field->column === $association->sourceColumn) {
                    $candidate = $field;
                    break;
                }
            }

            if ($candidate === null && $association->sourceField !== null && $metadata->hasField($association->sourceField)) {
                $candidate = $metadata->field($association->sourceField);
            }

            if ($candidate === null) {
                return null;
            }

            $value = $candidate->hasValue($entity) ? $candidate->getValue($entity) : null;

            if ($value === null || $value === '') {
                return null;
            }

            return is_int($value) || is_string($value) ? $value : (string) $value;
        }

        $sourceField = $metadata->field($association->sourceField . 'Id');
        $value = $sourceField->hasValue($entity) ? $sourceField->getValue($entity) : null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_int($value) || is_string($value) ? $value : (string) $value;
    }

    private function assignAssociationValue(object $entity, EntityAssociationMetadata $association, mixed $value): void
    {
        $property = $association->property;
        if (method_exists($property, 'setAccessible')) {
            $property->setAccessible(true);
        }
        $property->setValue($entity, $value);
    }
}
