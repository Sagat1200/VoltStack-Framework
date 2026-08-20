<?php declare(strict_types=1);

namespace Quantum\Database\Orm\EntityManager;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;

/**
 * Placeholder EntityManager interface (implementado en DDD-08).
 *
 * Se provee aquí para permitir que UnitOfWork (DDD-06) compile dependencias y
 * tenga placeholders para métodos internos.
 */
interface EntityManagerInterface
{
    public function getConnection(): ConnectionInterface;

    public function getMetadataFactory(): MetadataManagerInterface;

    public function persist(object $entity): void;

    public function remove(object $entity): void;

    public function flush(): void;

    public function clear(): void;

    public function contains(object $entity): bool;

    public function detach(object $entity): void;

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T|null
     */
    public function find(string $entityClass, mixed $id): ?object;

    public function getTenantId(): ?string;
}
