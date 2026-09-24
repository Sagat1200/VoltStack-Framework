<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Query\Builder\SelectQueryBuilder;
use Quantum\Database\Schema\SchemaManager;
use Quantum\Database\Support\DatabaseStatus;

interface DatabaseInterface
{
    public function connection(?string $name = null): ConnectionInterface;

    public function query(): DatabaseQueryManager;

    public function table(string $table, ?string $connectionName = null): SelectQueryBuilder;

    public function schema(?string $connectionName = null): SchemaManager;

    public function transaction(callable $callback, ?string $connectionName = null): mixed;

    public function migrate(?string $path = null, ?string $connectionName = null): int;

    public function rollbackLastBatch(?string $path = null, ?string $connectionName = null): int;

    public function status(?string $connectionName = null): DatabaseStatus;

    public function entityManager(): EntityManagerInterface;

    public function repository(string $entityClass): EntityRepositoryInterface;
}
