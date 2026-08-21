<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Repository;

use Quantum\Database\Orm\EntityManager\EntityManager;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Query\SelectQueryBuilder;

/**
 * Repositorio por defecto del ORM V1.
 *
 * @template T of object
 */
class DefaultRepository
{
    /** @param class-string<T> $entityClass */
    public function __construct(
        protected readonly EntityManager $em,
        protected readonly CompiledEntityMetadata $classMetadata,
        protected readonly string $entityClass,
    ) {}

    /** @return class-string<T> */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /**
     * @return T|null
     */
    public function find(mixed $id): ?object
    {
        return $this->em->find($this->entityClass, $id);
    }

    /**
     * @return list<T>
     */
    public function findAll(): array
    {
        return $this->em->findAll($this->entityClass);
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return list<T>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->em->findBy($this->entityClass, $criteria, $orderBy, $limit, $offset);
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return T|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->em->findOneBy($this->entityClass, $criteria, $orderBy);
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        return $this->em->count($this->entityClass, $criteria);
    }

    public function createQueryBuilder(?string $alias = null): SelectQueryBuilder
    {
        $resolvedAlias = $alias;
        if ($resolvedAlias === null || $resolvedAlias === '') {
            $resolvedAlias = strtolower((new \ReflectionClass($this->entityClass))->getShortName());
        }

        return $this->em->createQueryBuilder($this->entityClass, $resolvedAlias);
    }

    public function getEntityManager(): EntityManager
    {
        return $this->em;
    }

    public function getClassMetadata(): CompiledEntityMetadata
    {
        return $this->classMetadata;
    }
}
