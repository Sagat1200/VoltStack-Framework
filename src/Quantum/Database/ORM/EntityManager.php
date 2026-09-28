<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\Contracts\TransactionManagerInterface;
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
        $operations = function (): void {
            foreach ($this->unitOfWork->newEntities() as $entity) {
                $this->flushInsert($entity);
            }

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
    }

    private function flushUpdate(object $entity): void
    {
        $metadata = $this->unitOfWork->metadataFor($entity);
        $key = $this->requireKey($entity);
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

        $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->update($changes);

        $this->unitOfWork->synchronize($entity, $key);
    }

    private function flushDelete(object $entity): void
    {
        $metadata = $this->unitOfWork->metadataFor($entity);
        $key = $this->requireKey($entity);

        $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->delete();

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
