<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use RuntimeException;

final class UnitOfWork
{
    /**
     * @var array<int, object>
     */
    private array $entities = [];

    /**
     * @var array<int, EntityMetadata>
     */
    private array $metadata = [];

    /**
     * @var array<int, EntityState>
     */
    private array $states = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $snapshots = [];

    /**
     * @var array<int, EntityKey|null>
     */
    private array $keys = [];

    /**
     * Original to-many collection snapshots used by OrphanRemoval and ManyToMany
     * membership diffing.
     * Shape: spl_object_id → association_name → list<spl_object_id of each collection item>
     *
     * @var array<int, array<string, list<int>>>
     */
    private array $originalCollections = [];

    public function registerManaged(object $entity, EntityMetadata $metadata, EntityKey $key): void
    {
        $oid = spl_object_id($entity);

        $this->entities[$oid] = $entity;
        $this->metadata[$oid] = $metadata;
        $this->states[$oid] = EntityState::Managed;
        $this->snapshots[$oid] = $metadata->extract($entity);
        $this->keys[$oid] = $key;
        $this->snapshotOneToManyCollections($entity, $metadata);
    }

    public function persist(object $entity, EntityMetadata $metadata, ?EntityKey $key): void
    {
        $oid = spl_object_id($entity);

        if (isset($this->states[$oid])) {
            if ($this->states[$oid] === EntityState::Removed) {
                $this->states[$oid] = EntityState::Managed;
            }

            return;
        }

        $this->entities[$oid] = $entity;
        $this->metadata[$oid] = $metadata;
        $this->states[$oid] = EntityState::New;
        $this->snapshots[$oid] = [];
        $this->keys[$oid] = $key;
    }

    public function remove(object $entity): void
    {
        $oid = spl_object_id($entity);
        $state = $this->states[$oid] ?? null;

        if ($state === null) {
            throw new RuntimeException('Cannot remove an unmanaged entity.');
        }

        if ($state === EntityState::New) {
            $this->detach($entity);

            return;
        }

        $this->states[$oid] = EntityState::Removed;
    }

    public function detach(object $entity): void
    {
        $oid = spl_object_id($entity);

        unset(
            $this->entities[$oid],
            $this->metadata[$oid],
            $this->states[$oid],
            $this->snapshots[$oid],
            $this->keys[$oid],
            $this->originalCollections[$oid],
        );
    }

    public function contains(object $entity): bool
    {
        return isset($this->states[spl_object_id($entity)]);
    }

    public function state(object $entity): EntityState
    {
        return $this->states[spl_object_id($entity)] ?? EntityState::Detached;
    }

    /**
     * @return list<object>
     */
    public function newEntities(): array
    {
        return $this->entitiesByState(EntityState::New);
    }

    /**
     * @return list<object>
     */
    public function managedEntities(): array
    {
        return $this->entitiesByState(EntityState::Managed);
    }

    /**
     * @return list<object>
     */
    public function removedEntities(): array
    {
        return $this->entitiesByState(EntityState::Removed);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(object $entity): array
    {
        return $this->snapshots[spl_object_id($entity)] ?? [];
    }

    public function metadataFor(object $entity): EntityMetadata
    {
        $oid = spl_object_id($entity);

        if (! isset($this->metadata[$oid])) {
            throw new RuntimeException('Metadata is not available for the given entity.');
        }

        return $this->metadata[$oid];
    }

    public function keyFor(object $entity): ?EntityKey
    {
        return $this->keys[spl_object_id($entity)] ?? null;
    }

    public function synchronize(object $entity, EntityKey $key): void
    {
        $oid = spl_object_id($entity);
        $metadata = $this->metadataFor($entity);

        $this->states[$oid] = EntityState::Managed;
        $this->snapshots[$oid] = $metadata->extract($entity);
        $this->keys[$oid] = $key;
        $this->snapshotOneToManyCollections($entity, $metadata);
    }

    public function clear(): void
    {
        $this->entities = [];
        $this->metadata = [];
        $this->states = [];
        $this->snapshots = [];
        $this->keys = [];
        $this->originalCollections = [];
    }

    /**
     * Capture the current identity of to-many collection items for a given
     * managed entity.
     */
    public function snapshotOneToManyCollections(object $entity, EntityMetadata $metadata): void
    {
        $oid = spl_object_id($entity);
        $this->originalCollections[$oid] = [];

        foreach ($metadata->associations() as $assoc) {
            if (! $assoc->isToMany()) {
                continue;
            }

            $this->originalCollections[$oid][$assoc->name] = $this->collectObjectIdsFromCollection(
                $this->readToManyCollection($assoc, $entity),
            );
        }
    }

    /**
     * Compute {removed, added} for a to-many collection compared to its
     * snapshot.
     *
     * @return array{removed: list<object>, added: list<object>}
     */
    public function collectionDiff(object $entity, string $associationName): array
    {
        $oid = spl_object_id($entity);
        $metadata = $this->metadataFor($entity);
        $assoc = $metadata->association($associationName);

        if (! $assoc->isToMany()) {
            throw new RuntimeException(sprintf(
                'Association [%s::$%s] is not a to-many collection.',
                $metadata->className,
                $associationName,
            ));
        }

        $current = $this->readToManyCollection($assoc, $entity);
        $currentIds = $this->collectObjectIdsFromCollection($current);
        $originalIds = $this->originalCollections[$oid][$associationName] ?? [];

        $currentById = [];
        foreach ($current as $item) {
            $currentById[spl_object_id($item)] = $item;
        }

        $removed = [];
        $removedIds = array_diff($originalIds, $currentIds);
        // Items previously in the snapshot that are still alive may have been
        // re-associated; resolve them from the identity map when still tracked.
        foreach ($this->entities as $trackedEntity) {
            $trackedOid = spl_object_id($trackedEntity);
            if (in_array($trackedOid, $removedIds, true)) {
                $removed[] = $trackedEntity;
            }
        }

        $added = [];
        $addedIds = array_diff($currentIds, $originalIds);
        foreach ($addedIds as $oidAdded) {
            if (isset($currentById[$oidAdded])) {
                $added[] = $currentById[$oidAdded];
            }
        }

        return [
            'removed' => array_values($removed),
            'added'   => array_values($added),
        ];
    }

    /**
     * @return iterable<object>
     */
    private function readToManyCollection(EntityAssociationMetadata $assoc, object $entity): iterable
    {
        $property = $assoc->property;
        $property->setAccessible(true);
        $raw = $property->getValue($entity);
        if ($raw === null) {
            return [];
        }

        if (! is_iterable($raw)) {
            return [];
        }

        return $raw;
    }

    /**
     * @param iterable<object> $collection
     *
     * @return list<int>
     */
    private function collectObjectIdsFromCollection(iterable $collection): array
    {
        $ids = [];
        foreach ($collection as $item) {
            if (! is_object($item)) {
                continue;
            }

            $ids[] = spl_object_id($item);
        }

        return $ids;
    }

    /**
     * @return list<object>
     */
    private function entitiesByState(EntityState $state): array
    {
        $entities = [];

        foreach ($this->states as $oid => $current) {
            if ($current !== $state) {
                continue;
            }

            $entities[] = $this->entities[$oid];
        }

        return $entities;
    }
}
