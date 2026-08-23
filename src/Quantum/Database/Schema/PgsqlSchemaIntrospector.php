<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

final class PgsqlSchemaIntrospector implements SchemaIntrospectorInterface
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function listTables(): array
    {
        $this->connection->connect();

        $rows = $this->connection->executeQuery(
            "SELECT table_schema, table_name FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY table_schema ASC, table_name ASC"
        )->fetchAllAssoc();

        return array_values(array_map(
            static fn(array $row): string => (($row['table_schema'] ?? 'public') === 'public')
                ? (string) ($row['table_name'] ?? '')
                : (string) (($row['table_schema'] ?? '') . '.' . ($row['table_name'] ?? '')),
            $rows,
        ));
    }

    public function tableExists(string $table): bool
    {
        $this->connection->connect();
        [$schema, $name] = $this->splitTableIdentifier($table);

        $row = $this->connection->executeQuery(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = COALESCE(?, current_schema()) AND table_name = ? AND table_type = 'BASE TABLE' LIMIT 1",
            [$schema, $name],
        )->fetchOneAssoc();

        return is_array($row);
    }

    public function describeTable(string $table): SchemaTable
    {
        $this->connection->connect();
        [$schema, $name] = $this->splitTableIdentifier($table);

        $rows = $this->connection->executeQuery(
            "SELECT table_schema, column_name, data_type, udt_name, is_nullable, column_default, ordinal_position, character_maximum_length, numeric_precision, numeric_scale, is_identity FROM information_schema.columns WHERE table_schema = COALESCE(?, current_schema()) AND table_name = ? ORDER BY ordinal_position ASC",
            [$schema, $name],
        )->fetchAllAssoc();

        if ($rows === []) {
            throw new \RuntimeException(sprintf('Table [%s] was not found in the current schema.', $table));
        }

        $columns = [];

        foreach ($rows as $row) {
            $portableType = $this->mapPortableType((string) ($row['data_type'] ?? ''), (string) ($row['udt_name'] ?? ''));
            $columns[] = new SchemaColumn(
                name: (string) ($row['column_name'] ?? ''),
                nativeType: $this->buildNativeType($row),
                nullable: strtoupper((string) ($row['is_nullable'] ?? 'YES')) === 'YES',
                defaultValue: $row['column_default'] ?? null,
                primaryKey: false,
                autoIncrement: strtoupper((string) ($row['is_identity'] ?? 'NO')) === 'YES'
                    || str_contains(strtolower((string) ($row['column_default'] ?? '')), 'nextval('),
                ordinal: max(0, ((int) ($row['ordinal_position'] ?? 1)) - 1),
                portableType: $portableType,
                length: isset($row['character_maximum_length']) ? (int) $row['character_maximum_length'] : null,
                precision: isset($row['numeric_precision']) ? (int) $row['numeric_precision'] : null,
                scale: isset($row['numeric_scale']) ? (int) $row['numeric_scale'] : null,
            );
        }

        $resolvedSchema = (string) ($rows[0]['table_schema'] ?? $schema ?? 'public');
        $primaryKey = $this->loadPrimaryKey($resolvedSchema, $name);
        $indexes = $this->loadIndexes($resolvedSchema, $name);
        $foreignKeys = $this->loadForeignKeys($resolvedSchema, $name);

        $primaryMap = array_fill_keys($primaryKey, true);
        $normalizedColumns = [];
        foreach ($columns as $column) {
            $normalizedColumns[] = new SchemaColumn(
                name: $column->name,
                nativeType: $column->nativeType,
                nullable: $column->nullable,
                defaultValue: $column->defaultValue,
                primaryKey: isset($primaryMap[$column->name]),
                autoIncrement: $column->autoIncrement,
                ordinal: $column->ordinal,
                portableType: $column->portableType,
                length: $column->length,
                precision: $column->precision,
                scale: $column->scale,
            );
        }

        return new SchemaTable(
            name: $name,
            columns: $normalizedColumns,
            primaryKey: $primaryKey,
            createSql: null,
            schemaName: $resolvedSchema,
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
    private function splitTableIdentifier(string $table): array
    {
        $segments = explode('.', $table, 2);
        if (count($segments) === 2) {
            return [$segments[0], $segments[1]];
        }

        return [null, $table];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function buildNativeType(array $row): string
    {
        $dataType = strtolower((string) ($row['data_type'] ?? ''));
        $udtName = strtolower((string) ($row['udt_name'] ?? ''));
        $length = isset($row['character_maximum_length']) ? (int) $row['character_maximum_length'] : null;
        $precision = isset($row['numeric_precision']) ? (int) $row['numeric_precision'] : null;
        $scale = isset($row['numeric_scale']) ? (int) $row['numeric_scale'] : null;

        return match ($dataType) {
            'character varying' => sprintf('VARCHAR(%d)', $length ?? 255),
            'character' => sprintf('CHAR(%d)', $length ?? 1),
            'numeric' => $precision !== null
                ? sprintf('NUMERIC(%d%s)', $precision, $scale !== null ? ',' . $scale : '')
                : 'NUMERIC',
            'timestamp without time zone' => 'TIMESTAMP',
            'timestamp with time zone' => 'TIMESTAMPTZ',
            'time without time zone' => 'TIME',
            'time with time zone' => 'TIMETZ',
            'double precision' => 'DOUBLE PRECISION',
            'user-defined' => strtoupper($udtName),
            default => strtoupper($dataType !== '' ? $dataType : $udtName),
        };
    }

    private function mapPortableType(string $dataType, string $udtName): ?string
    {
        $dataType = strtolower($dataType);
        $udtName = strtolower($udtName);

        return match (true) {
            $dataType === 'smallint' || $udtName === 'int2' => 'smallint',
            $dataType === 'integer' || $udtName === 'int4' => 'integer',
            $dataType === 'bigint' || $udtName === 'int8' => 'bigint',
            $dataType === 'boolean' || $udtName === 'bool' => 'boolean',
            $dataType === 'real' || $udtName === 'float4' => 'float',
            $dataType === 'double precision' || $udtName === 'float8' => 'double',
            $dataType === 'numeric' => 'decimal',
            $dataType === 'character varying' => 'varchar',
            $dataType === 'character' => 'char',
            $dataType === 'text' => 'text',
            $dataType === 'bytea' => 'blob',
            $dataType === 'date' => 'date',
            str_starts_with($dataType, 'time ') || $dataType === 'time' => 'time',
            str_starts_with($dataType, 'timestamp ') || $dataType === 'timestamp' => 'timestamp',
            in_array($dataType, ['json', 'jsonb'], true) => 'json',
            $dataType === 'uuid' || $udtName === 'uuid' => 'uuid',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function loadPrimaryKey(string $schema, string $table): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT kcu.column_name FROM information_schema.table_constraints tc INNER JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema AND kcu.table_name = tc.table_name WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = ? AND tc.table_name = ? ORDER BY kcu.ordinal_position ASC",
            [$schema, $table],
        )->fetchAllAssoc();

        return array_values(array_map(static fn(array $row): string => (string) ($row['column_name'] ?? ''), $rows));
    }

    /**
     * @return list<SchemaIndex>
     */
    private function loadIndexes(string $schema, string $table): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT i.relname AS index_name, ix.indisunique AS is_unique, ix.indisprimary AS is_primary, string_agg(a.attname, ',' ORDER BY ord.ordinality) AS columns FROM pg_class t INNER JOIN pg_namespace ns ON ns.oid = t.relnamespace INNER JOIN pg_index ix ON ix.indrelid = t.oid INNER JOIN pg_class i ON i.oid = ix.indexrelid INNER JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS ord(attnum, ordinality) ON true INNER JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ord.attnum WHERE ns.nspname = ? AND t.relname = ? GROUP BY i.relname, ix.indisunique, ix.indisprimary ORDER BY i.relname ASC",
            [$schema, $table],
        )->fetchAllAssoc();

        $indexes = [];

        foreach ($rows as $row) {
            $indexName = (string) ($row['index_name'] ?? '');
            if ($indexName === '') {
                continue;
            }

            $columns = array_values(array_filter(array_map('trim', explode(',', (string) ($row['columns'] ?? ''))), static fn(string $column): bool => $column !== ''));

            $indexes[] = new SchemaIndex(
                name: $indexName,
                columns: $columns,
                unique: $this->parseBool($row['is_unique'] ?? false),
                primary: $this->parseBool($row['is_primary'] ?? false),
            );
        }

        return $indexes;
    }

    /**
     * @return list<SchemaForeignKey>
     */
    private function loadForeignKeys(string $schema, string $table): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT tc.constraint_name, kcu.column_name, ccu.table_schema AS referenced_schema, ccu.table_name AS referenced_table, ccu.column_name AS referenced_column, rc.update_rule, rc.delete_rule, kcu.ordinal_position FROM information_schema.table_constraints tc INNER JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema AND kcu.table_name = tc.table_name INNER JOIN information_schema.referential_constraints rc ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema INNER JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = rc.unique_constraint_name AND ccu.constraint_schema = rc.unique_constraint_schema WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ? AND tc.table_name = ? ORDER BY tc.constraint_name ASC, kcu.ordinal_position ASC",
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
                referencedTable: (string) ($items[0]['referenced_table'] ?? ''),
                referencedColumns: array_values(array_map(static fn(array $item): string => (string) ($item['referenced_column'] ?? ''), $items)),
                referencedSchema: isset($items[0]['referenced_schema']) ? (string) $items[0]['referenced_schema'] : null,
                onUpdate: isset($items[0]['update_rule']) ? strtoupper((string) $items[0]['update_rule']) : null,
                onDelete: isset($items[0]['delete_rule']) ? strtoupper((string) $items[0]['delete_rule']) : null,
            );
        }

        return $foreignKeys;
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 't', 'true', 'yes', 'y', 'on'], true);
    }
}
