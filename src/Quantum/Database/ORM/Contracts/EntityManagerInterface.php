<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Contracts;

use Quantum\Database\ORM\EntityQuery;
use Quantum\Database\ORM\EntityState;

interface EntityManagerInterface
{
    public function find(string $entityClass, mixed $identifier): ?object;

    public function persist(object $entity): void;

    public function remove(object $entity): void;

    public function refresh(object $entity): void;

    public function flush(): void;

    public function clear(): void;

    public function contains(object $entity): bool;

    public function state(object $entity): EntityState;

    public function repository(string $entityClass): EntityRepositoryInterface;

    public function query(string $entityClass): EntityQuery;
}
