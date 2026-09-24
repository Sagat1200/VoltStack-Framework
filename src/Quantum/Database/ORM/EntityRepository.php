<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\ORM\Metadata\EntityMetadata;

class EntityRepository implements EntityRepositoryInterface
{
    public function __construct(
        protected readonly EntityManager $manager,
        protected readonly EntityMetadata $metadata,
    ) {
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
}
