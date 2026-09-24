<?php

declare(strict_types=1);

namespace Quantum\Database;

use Quantum\Database\Config\DatabaseConfiguration;
use Quantum\Database\Contracts\ConnectionInterface;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Database\Contracts\TransactionManagerInterface;
use Quantum\Database\Migration\MigrationRepository;
use Quantum\Database\Migration\MigrationRunner;
use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Query\Builder\SelectQueryBuilder;
use Quantum\Database\Schema\SchemaManager;
use Quantum\Database\Support\DatabaseStatus;

final class Database implements DatabaseInterface
{
    public function __construct(
        private readonly DatabaseConfiguration $configuration,
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseQueryManager $queries,
        private readonly SchemaManager $schemaManager,
        private readonly MigrationRepository $migrationRepository,
        private readonly MigrationRunner $migrations,
        private readonly TransactionManagerInterface $transactions,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function connection(?string $name = null): ConnectionInterface
    {
        return $this->connections->connection($name);
    }

    public function query(): DatabaseQueryManager
    {
        return $this->queries;
    }

    public function table(string $table, ?string $connectionName = null): SelectQueryBuilder
    {
        return $this->queries->table($table, $connectionName);
    }

    public function schema(?string $connectionName = null): SchemaManager
    {
        if ($connectionName === null) {
            return $this->schemaManager;
        }

        return $this->schemaManager->connection($connectionName);
    }

    public function transaction(callable $callback, ?string $connectionName = null): mixed
    {
        return $this->transactions->transaction($callback, $connectionName);
    }

    public function migrate(?string $path = null, ?string $connectionName = null): int
    {
        return $this->migrations->migrate($path, $connectionName);
    }

    public function rollbackLastBatch(?string $path = null, ?string $connectionName = null): int
    {
        return $this->migrations->rollbackLastBatch($path, $connectionName);
    }

    public function status(?string $connectionName = null): DatabaseStatus
    {
        $connection = $this->connection($connectionName);
        $target = $connection->name();
        $repository = $this->migrationRepository->connection($target);
        $repositoryExists = $this->schema($target)->hasTable($repository->tableName());

        return new DatabaseStatus(
            defaultConnectionName: $this->configuration->defaultConnectionName,
            connectionName: $target,
            driver: $connection->driver()->id(),
            platform: $connection->platform()->id(),
            database: $connection->definition()->database,
            telemetryEnabled: (bool) $this->configuration->telemetryOption('enabled', true),
            migrationRepositoryExists: $repositoryExists,
            appliedMigrations: $repositoryExists ? $repository->count() : 0,
            connected: $connection->isConnected(),
        );
    }

    public function entityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function repository(string $entityClass): EntityRepositoryInterface
    {
        return $this->entityManager->repository($entityClass);
    }
}
