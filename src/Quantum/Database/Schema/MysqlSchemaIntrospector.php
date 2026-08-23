<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

class MysqlSchemaIntrospector implements SchemaIntrospectorInterface
{
    public function __construct(protected readonly ConnectionInterface $connection)
    {
    }

    public function listTables(): array
    {
        $this->connection->connect();

        $rows = $this->connection->executeQuery(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name ASC"
        )->fetchAllAssoc();

        return array_values(array_map(static fn(array $row): string => (string) ($row['table_name'] ?? ''), $rows));
    }

    public function tableExists(string $table): bool
    {
        $this->connection->connect();
        [$schema, $name] = $this->splitTableIdentifier($table);

        $row = $this->connection->executeQuery(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = COALESCE(?, DATABASE()) AND table_name = ? AND table_type = ? LIMIT 1',
            [$schema, $name, 'BASE TABLE'],
        )->fetchOneAssoc();

        return is_array($row);
    }

    public function describeTable(string $table): SchemaTable
    {
        $this->connection->connect();
        [$schema, $name] = $this->splitTableIdentifier($table);

        $rows = $this->connection->executeQuery(
            'SELECT table_schema, column_name, column_type, data_type, is_nullable, column_default, column_key, extra, ordinal_position, character_maximum_length, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = COALESCE(?, DATABASE()) AND table_name = ? ORDER BY ordinal_position ASC',
            [$schema, $name],
        )->fetchAllAssoc();

        if ($rows === []) {
            throw new \RuntimeException(sprintf('Table [%s] was not found in the current schema.', $table));
        }

        $columns = [];

        foreach ($rows as $row) {
            $nativeType = strtoupper((string) ($row['column_type'] ?? $row['data_type'] ?? ''));

            $columns[] = new SchemaColumn(
                name: (string) ($row['column_name'] ?? ''),
                nativeType: $nativeType,
                nullable: strtoupper((string) ($row['is_nullable'] ?? 'YES')) === 'YES',
                defaultValue: $row['column_default'] ?? null,
                primaryKey: strtoupper((string) ($row['column_key'] ?? '')) === 'PRI',
                autoIncrement: str_contains(strtolower((string) ($row['extra'] ?? '')), 'auto_increment'),
                ordinal: max(0, ((int) ($row['ordinal_position'] ?? 1)) - 1),
                portableType: $this->mapPortableType(
                    (string) ($row['data_type'] ?? ''),
                    (string) ($row['column_type'] ?? ''),
                ),
                length: isset($row['character_maximum_length']) ? (int) $row['character_maximum_length'] : null,
                precision: isset($row['numeric_precision']) ? (int) $row['numeric_precision'] : null,
                scale: isset($row['numeric_scale']) ? (int) $row['numeric_scale'] : null,
            );
        }

        $resolvedSchema = (string) ($rows[0]['table_schema'] ?? $schema ?? '');
        $primaryKey = $this->loadPrimaryKey($resolvedSchema, $name);
        $indexes = $this->loadIndexes($resolvedSchema, $name);
        $foreignKeys = $this->loadForeignKeys($resolvedSchema, $name);

        return new SchemaTable(
            name: $name,
            columns: $columns,
            primaryKey: $primaryKey,
            primaryKeyName: $primaryKey !== [] ? 'PRIMARY' : null,
            createSql: null,
            schemaName: $resolvedSchema !== '' ? $resolvedSchema : null,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
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

    /**
     * @return array{0:?string,1:string}
     */
    protected function splitTableIdentifier(string $table): array
    {
        $segments = explode('.', $table, 2);
        if (count($segments) === 2) {
            return [$segments[0], $segments[1]];
        }

        return [null, $table];
    }

    protected function mapPortableType(string $dataType, string $columnType): ?string
    {
        $dataType = strtolower($dataType);
        $columnType = strtolower($columnType);

        return match (true) {
            $dataType === 'tinyint' && str_starts_with($columnType, 'tinyint(1)') => 'boolean',
            in_array($dataType, ['tinyint', 'smallint'], true) => 'smallint',
            in_array($dataType, ['mediumint', 'int', 'integer'], true) => 'integer',
            $dataType === 'bigint' => 'bigint',
            in_array($dataType, ['decimal', 'numeric'], true) => 'decimal',
            $dataType === 'float' => 'float',
            in_array($dataType, ['double', 'real'], true) => 'double',
            $dataType === 'char' => 'char',
            in_array($dataType, ['varchar', 'enum'], true) => 'varchar',
            str_contains($dataType, 'text') => 'text',
            str_contains($dataType, 'blob') || in_array($dataType, ['binary', 'varbinary'], true) => 'blob',
            $dataType === 'date' => 'date',
            $dataType === 'time' => 'time',
            in_array($dataType, ['datetime', 'timestamp'], true) => 'timestamp',
            $dataType === 'json' => 'json',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    protected function loadPrimaryKey(string $schema, string $table): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT column_name FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND constraint_name = 'PRIMARY' ORDER BY ordinal_position ASC",
            [$schema, $table],
        )->fetchAllAssoc();

        return array_values(array_map(static fn(array $row): string => (string) ($row['column_name'] ?? ''), $rows));
    }

    /**
     * @return list<SchemaIndex>
     */
    protected function loadIndexes(string $schema, string $table): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT index_name, non_unique, seq_in_index, column_name FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? ORDER BY index_name ASC, seq_in_index ASC',
            [$schema, $table],
        )->fetchAllAssoc();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) ($row['index_name'] ?? '')][] = $row;
        }

        $indexes = [];

        foreach ($grouped as $indexName => $items) {
            if ($indexName === '') {
                continue;
            }

            $indexes[] = new SchemaIndex(
                name: $indexName,
                columns: array_values(array_map(static fn(array $item): string => (string) ($item['column_name'] ?? ''), $items)),
                unique: ((int) ($items[0]['non_unique'] ?? 1)) === 0,
                primary: strtoupper($indexName) === 'PRIMARY',
            );
        }

        return $indexes;
    }

    /**
     * @return list<SchemaForeignKey>
     */
    protected function loadForeignKeys(string $schema, string $table): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT rc.constraint_name, kcu.column_name, kcu.referenced_table_schema, kcu.referenced_table_name, kcu.referenced_column_name, rc.update_rule, rc.delete_rule, kcu.ordinal_position FROM information_schema.referential_constraints rc INNER JOIN information_schema.key_column_usage kcu ON kcu.constraint_schema = rc.constraint_schema AND kcu.constraint_name = rc.constraint_name AND kcu.table_name = rc.table_name WHERE rc.constraint_schema = ? AND rc.table_name = ? ORDER BY rc.constraint_name ASC, kcu.ordinal_position ASC',
            [$schema, $table],
        )->fetchAllAssoc();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) ($row['constraint_name'] ?? '')][] = $row;
        }

        $foreignKeys = [];

        foreach ($grouped as $constraintName => $items) {
            if ($constraintName === '') {
                continue;
            }

            $foreignKeys[] = new SchemaForeignKey(
                name: $constraintName,
                columns: array_values(array_map(static fn(array $item): string => (string) ($item['column_name'] ?? ''), $items)),
                referencedTable: (string) ($items[0]['referenced_table_name'] ?? ''),
                referencedColumns: array_values(array_map(static fn(array $item): string => (string) ($item['referenced_column_name'] ?? ''), $items)),
                referencedSchema: isset($items[0]['referenced_table_schema']) ? (string) $items[0]['referenced_table_schema'] : null,
                onUpdate: isset($items[0]['update_rule']) ? strtoupper((string) $items[0]['update_rule']) : null,
                onDelete: isset($items[0]['delete_rule']) ? strtoupper((string) $items[0]['delete_rule']) : null,
            );
        }

        return $foreignKeys;
    }
}
