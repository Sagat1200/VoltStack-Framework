<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Query\Builder\SelectQueryBuilder;

final class EntityQuery
{
    private SelectQueryBuilder $query;

    public function __construct(
        private readonly EntityManager $manager,
        private readonly EntityMetadata $metadata,
        DatabaseQueryManager $queries,
    ) {
        $this->query = $queries->table($metadata->table);
    }

    public function where(string $field, mixed $operatorOrValue, mixed $value = null): self
    {
        $column = $this->metadata->field($field)->column;

        if (func_num_args() === 2) {
            $this->query->where($column, $operatorOrValue);

            return $this;
        }

        $this->query->where($column, $operatorOrValue, $value);

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->query->orderBy($this->metadata->field($field)->column, $direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query->limit($limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->query->offset($offset);

        return $this;
    }

    /**
     * @return list<object>
     */
    public function get(): array
    {
        $rows = $this->query->get()->rows();
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->manager->hydrateManaged($this->metadata, $row);
        }

        return $entities;
    }

    public function first(): ?object
    {
        $row = $this->query->first();

        if ($row === null) {
            return null;
        }

        return $this->manager->hydrateManaged($this->metadata, $row);
    }
}
