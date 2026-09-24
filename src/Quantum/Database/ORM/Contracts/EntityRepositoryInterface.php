<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Contracts;

use Quantum\Database\ORM\EntityQuery;

interface EntityRepositoryInterface
{
    public function find(mixed $identifier): ?object;

    /**
     * @return list<object>
     */
    public function findAll(): array;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return list<object>
     */
    public function findBy(
        array $criteria,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array;

    /**
     * @param array<string, mixed> $criteria
     */
    public function findOneBy(array $criteria): ?object;

    public function query(): EntityQuery;
}
