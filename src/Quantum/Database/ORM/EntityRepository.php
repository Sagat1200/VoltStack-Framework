<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use RuntimeException;

class EntityRepository implements EntityRepositoryInterface
{
    public function __construct(
        protected readonly EntityManager $manager,
        protected readonly EntityMetadata $metadata,
    ) {
    }

    public function getEntityManager(): EntityManager
    {
        return $this->manager;
    }

    public function getMetadata(): EntityMetadata
    {
        return $this->metadata;
    }

    public function getEntityClass(): string
    {
        return $this->metadata->className;
    }

    public function find(mixed $identifier): ?object
    {
        return $this->manager->find($this->metadata->className, $identifier);
    }

    public function findAll(): array
    {
        return $this->query()->get();
    }

    public function findBy(
        array $criteria,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $query = $this->query();

        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }

        foreach ($orderBy ?? [] as $field => $direction) {
            $query->orderBy($field, $direction);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        if ($offset !== null) {
            $query->offset($offset);
        }

        return $query->get();
    }

    public function findOneBy(array $criteria): ?object
    {
        $query = $this->query();

        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }

        return $query->limit(1)->first();
    }

    public function query(): EntityQuery
    {
        return $this->manager->query($this->metadata->className);
    }

    public function save(object $entity, bool $flush = false): void
    {
        $expectedClass = $this->metadata->className;

        if (! $entity instanceof $expectedClass) {
            throw new RuntimeException(sprintf(
                'Entity repository for [%s] cannot save instance of [%s].',
                $expectedClass,
                $entity::class,
            ));
        }

        $this->manager->persist($entity);

        if ($flush) {
            $this->manager->flush();
        }
    }

    public function delete(object $entity, bool $flush = false): void
    {
        $expectedClass = $this->metadata->className;

        if (! $entity instanceof $expectedClass) {
            throw new RuntimeException(sprintf(
                'Entity repository for [%s] cannot delete instance of [%s].',
                $expectedClass,
                $entity::class,
            ));
        }

        $this->manager->remove($entity);

        if ($flush) {
            $this->manager->flush();
        }
    }

    public function count(array $criteria = []): int
    {
        $query = $this->query();

        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }

        return $query->count();
    }

    public function exists(array $criteria): bool
    {
        return $this->count($criteria) > 0;
    }
}
