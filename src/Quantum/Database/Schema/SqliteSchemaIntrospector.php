<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

final class SqliteSchemaIntrospector implements SchemaIntrospectorInterface
{
    public function __construct(private readonly ConnectionInterface $connection) {}

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
            $nativeType = strtoupper((string) ($row['type'] ?? ''));
            [$portableType, $length, $precision, $scale] = $this->parseDeclaredType($nativeType);
            $column = new SchemaColumn(
                name: (string) ($row['name'] ?? ''),
                nativeType: $nativeType,
                nullable: ((int) ($row['notnull'] ?? 0)) !== 1,
                defaultValue: $row['dflt_value'] ?? null,
                primaryKey: ((int) ($row['pk'] ?? 0)) > 0,
                autoIncrement: ((int) ($row['pk'] ?? 0)) > 0 && $autoIncrement,
                ordinal: (int) ($row['cid'] ?? 0),
                portableType: $portableType,
                length: $length,
                precision: $precision,
                scale: $scale,
            );

            $columns[] = $column;

            if ($column->primaryKey) {
                $primaryKey[(int) ($row['pk'] ?? 0)] = $column->name;
            }
        }

        ksort($primaryKey, \SORT_NUMERIC);

        $indexes = $this->loadIndexes($table);
        $foreignKeys = $this->loadForeignKeys($table);

        return new SchemaTable(
            name: $table,
            columns: $columns,
            primaryKey: array_values($primaryKey),
            primaryKeyName: null,
            createSql: $createSql,
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
     * @return array{0:?string,1:?int,2:?int,3:?int}
     */
    private function parseDeclaredType(string $type): array
    {
        $normalized = strtoupper(trim($type));
        $length = null;
        $precision = null;
        $scale = null;

        if (preg_match('/^[A-Z ]+\((\d+)(?:,(\d+))?\)$/', $normalized, $matches) === 1) {
            if (isset($matches[2]) && $matches[2] !== '') {
                $precision = (int) $matches[1];
                $scale = (int) $matches[2];
            } else {
                $length = (int) $matches[1];
            }
        }

        return [match (true) {
            str_contains($normalized, 'BIGINT') => 'bigint',
            str_contains($normalized, 'SMALLINT') => 'smallint',
            str_contains($normalized, 'INT') => 'integer',
            str_contains($normalized, 'BOOL') => 'boolean',
            str_contains($normalized, 'DOUBLE') => 'double',
            str_contains($normalized, 'REAL'), str_contains($normalized, 'FLOAT') => 'float',
            str_contains($normalized, 'DECIMAL'), str_contains($normalized, 'NUMERIC') => 'decimal',
            str_contains($normalized, 'VARCHAR') => 'varchar',
            str_contains($normalized, 'CHAR') => 'char',
            str_contains($normalized, 'JSON') => 'json',
            str_contains($normalized, 'UUID') => 'uuid',
            str_contains($normalized, 'DATE') && !str_contains($normalized, 'TIME') => 'date',
            str_contains($normalized, 'TIME'), str_contains($normalized, 'TIMESTAMP'), str_contains($normalized, 'DATETIME') => 'timestamp',
            str_contains($normalized, 'BLOB') => 'blob',
            str_contains($normalized, 'TEXT'), str_contains($normalized, 'CLOB') => 'text',
            $normalized === '' => null,
            default => 'text',
        }, $length, $precision, $scale];
    }

    /**
     * @return list<SchemaIndex>
     */
    private function loadIndexes(string $table): array
    {
        $pragmaTable = $this->connection->quoteIdentifier($table);
        $rows = $this->connection
            ->executeQuery(sprintf('PRAGMA index_list(%s)', $pragmaTable))
            ->fetchAllAssoc();

        $indexes = [];

        foreach ($rows as $row) {
            $indexName = (string) ($row['name'] ?? '');
            if ($indexName === '') {
                continue;
            }

            $quotedIndex = $this->connection->quoteIdentifier($indexName);
            $columns = $this->connection
                ->executeQuery(sprintf('PRAGMA index_info(%s)', $quotedIndex))
                ->fetchAllAssoc();

            usort($columns, static fn(array $a, array $b): int => ((int) ($a['seqno'] ?? 0)) <=> ((int) ($b['seqno'] ?? 0)));

            $indexes[] = new SchemaIndex(
                name: $indexName,
                columns: array_values(array_map(static fn(array $column): string => (string) ($column['name'] ?? ''), $columns)),
                unique: ((int) ($row['unique'] ?? 0)) === 1,
                primary: false,
            );
        }

        return $indexes;
    }

    /**
     * @return list<SchemaForeignKey>
     */
    private function loadForeignKeys(string $table): array
    {
        $pragmaTable = $this->connection->quoteIdentifier($table);
        $rows = $this->connection
            ->executeQuery(sprintf('PRAGMA foreign_key_list(%s)', $pragmaTable))
            ->fetchAllAssoc();

        $grouped = [];

        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '0');
            $grouped[$id][] = $row;
        }

        $foreignKeys = [];

        foreach ($grouped as $id => $items) {
            usort($items, static fn(array $a, array $b): int => ((int) ($a['seq'] ?? 0)) <=> ((int) ($b['seq'] ?? 0)));

            $foreignKeys[] = new SchemaForeignKey(
                name: 'fk_' . $table . '_' . $id,
                columns: array_values(array_map(static fn(array $item): string => (string) ($item['from'] ?? ''), $items)),
                referencedTable: (string) ($items[0]['table'] ?? ''),
                referencedColumns: array_values(array_map(static fn(array $item): string => (string) ($item['to'] ?? ''), $items)),
                referencedSchema: null,
                onUpdate: isset($items[0]['on_update']) ? strtoupper((string) $items[0]['on_update']) : null,
                onDelete: isset($items[0]['on_delete']) ? strtoupper((string) $items[0]['on_delete']) : null,
            );
        }

        return $foreignKeys;
    }
}
