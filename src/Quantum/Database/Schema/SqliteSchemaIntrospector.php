<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

final class SqliteSchemaIntrospector implements SchemaIntrospectorInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function listTables(): array
    {
        $this->connection->connect();

        $rows = $this->connection->executeQuery(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC"
        )->fetchAllAssoc();

        return array_values(array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $rows));
    }

    public function tableExists(string $table): bool
    {
        $this->connection->connect();

        $row = $this->connection->executeQuery(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
            [$table],
        )->fetchOneAssoc();

        return is_array($row);
    }

    public function describeTable(string $table): SchemaTable
    {
        $this->connection->connect();

        $meta = $this->connection->executeQuery(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
            [$table],
        )->fetchOneAssoc();

        if (!is_array($meta)) {
            throw new \RuntimeException(sprintf('Table [%s] was not found in the current schema.', $table));
        }

        $createSql = isset($meta['sql']) ? (string) $meta['sql'] : null;
        $pragmaTable = $this->connection->quoteIdentifier($table);
        $rows = $this->connection->executeQuery(sprintf('PRAGMA table_info(%s)', $pragmaTable))->fetchAllAssoc();

        $columns = [];
        $primaryKey = [];
        $autoIncrement = $createSql !== null && str_contains(strtoupper($createSql), 'AUTOINCREMENT');

        foreach ($rows as $row) {
            $column = new SchemaColumn(
                name: (string) ($row['name'] ?? ''),
                nativeType: strtoupper((string) ($row['type'] ?? '')),
                nullable: ((int) ($row['notnull'] ?? 0)) !== 1,
                defaultValue: $row['dflt_value'] ?? null,
                primaryKey: ((int) ($row['pk'] ?? 0)) > 0,
                autoIncrement: ((int) ($row['pk'] ?? 0)) > 0 && $autoIncrement,
                ordinal: (int) ($row['cid'] ?? 0),
            );

            $columns[] = $column;

            if ($column->primaryKey) {
                $primaryKey[(int) ($row['pk'] ?? 0)] = $column->name;
            }
        }

        ksort($primaryKey, \SORT_NUMERIC);

        return new SchemaTable(
            name: $table,
            columns: $columns,
            primaryKey: array_values($primaryKey),
            createSql: $createSql,
        );
    }

    public function snapshot(): SchemaSnapshot
    {
        $tables = [];

        foreach ($this->listTables() as $table) {
            $tables[] = $this->describeTable($table);
        }

        return new SchemaSnapshot(
            driver: $this->connection->getDriverInfo()->driverName,
            tables: $tables,
        );
    }
}
