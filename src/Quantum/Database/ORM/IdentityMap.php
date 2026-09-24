<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use RuntimeException;

final class IdentityMap
{
    /**
     * @var array<string, object>
     */
    private array $entitiesByKey = [];

    /**
     * @var array<int, EntityKey>
     */
    private array $keysByObject = [];

    public function contains(EntityKey $key): bool
    {
        return isset($this->entitiesByKey[$key->hash()]);
    }

    public function get(EntityKey $key): ?object
    {
        return $this->entitiesByKey[$key->hash()] ?? null;
    }

    public function register(EntityKey $key, object $entity): void
    {
        $hash = $key->hash();
        $existing = $this->entitiesByKey[$hash] ?? null;

        if ($existing !== null && $existing !== $entity) {
            throw new RuntimeException(sprintf(
                'Entity identity conflict for [%s].',
                $hash,
            ));
        }

        $this->entitiesByKey[$hash] = $entity;
        $this->keysByObject[spl_object_id($entity)] = $key;
    }

    public function remove(EntityKey $key): void
    {
        $hash = $key->hash();
        $entity = $this->entitiesByKey[$hash] ?? null;

        unset($this->entitiesByKey[$hash]);

        if ($entity !== null) {
            unset($this->keysByObject[spl_object_id($entity)]);
        }
    }

    public function keyOf(object $entity): ?EntityKey
    {
        return $this->keysByObject[spl_object_id($entity)] ?? null;
    }

    public function clear(): void
    {
        $this->entitiesByKey = [];
        $this->keysByObject = [];
    }

    public function count(): int
    {
        return count($this->entitiesByKey);
    }
}
