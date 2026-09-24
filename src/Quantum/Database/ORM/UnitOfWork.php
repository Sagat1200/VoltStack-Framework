<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

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

    public function registerManaged(object $entity, EntityMetadata $metadata, EntityKey $key): void
    {
        $oid = spl_object_id($entity);

        $this->entities[$oid] = $entity;
        $this->metadata[$oid] = $metadata;
        $this->states[$oid] = EntityState::Managed;
        $this->snapshots[$oid] = $metadata->extract($entity);
        $this->keys[$oid] = $key;
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
    }

    public function clear(): void
    {
        $this->entities = [];
        $this->metadata = [];
        $this->states = [];
        $this->snapshots = [];
        $this->keys = [];
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
