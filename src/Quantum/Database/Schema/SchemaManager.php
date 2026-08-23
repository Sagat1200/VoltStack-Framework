<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

final class SchemaManager
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SchemaIntrospectorInterface $introspector,
    ) {
    }

    /**
     * @return list<string>
     */
    public function listTables(): array
    {
        return $this->introspector->listTables();
    }

    public function tableExists(string $table): bool
    {
        return $this->introspector->tableExists($table);
    }

    public function describeTable(string $table): SchemaTable
    {
        return $this->introspector->describeTable($table);
    }

    public function snapshot(): SchemaSnapshot
    {
        return $this->introspector->snapshot();
    }

    public function driverName(): string
    {
        $this->connection->connect();
        return $this->connection->getDriverInfo()->driverName;
    }
}
