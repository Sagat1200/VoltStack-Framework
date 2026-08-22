<?php declare(strict_types=1);

namespace Quantum\Database\Orm\EntityManager;

use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dialect\DialectInterface;
use Quantum\Database\Orm\Hydration\HydratorInterface;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;
use Quantum\Database\Orm\UnitOfWork\IdentityMapInterface;
use Quantum\Database\Orm\UnitOfWork\UnitOfWork;

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

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return list<T>
     */
    public function findBy(string $entityClass, array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return T|null
     */
    public function findOneBy(string $entityClass, array $criteria, ?array $orderBy = null): ?object;

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return list<T>
     */
    public function findAll(string $entityClass): array;

    /**
     * @param class-string $entityClass
     * @param array<string,mixed> $criteria
     */
    public function count(string $entityClass, array $criteria = []): int;

    public function getTenantId(): ?string;

    public function getDialect(): DialectInterface;

    public function getHydrator(): HydratorInterface;

    public function getIdentityMap(): IdentityMapInterface;

    public function getContext(): DatabaseContext;

    public function getUnitOfWork(): UnitOfWork;
}
