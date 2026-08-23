<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final class SchemaComparator
{
    public function compare(SchemaSnapshot $actual, SchemaSnapshot $desired): SchemaDiffReport
    {
        $actions = [];
        $actualTables = [];

        foreach ($actual->tables as $table) {
            $actualTables[$this->tableKey($table)] = $table;
        }

        foreach ($desired->tables as $desiredTable) {
            $existing = $actualTables[$this->tableKey($desiredTable)] ?? null;

            if (!$existing instanceof SchemaTable) {
                $actions[] = new SchemaDiffAction(
                    kind: 'create_table',
                    table: $desiredTable->qualifiedName(),
                    column: null,
                    message: sprintf('Create missing table [%s].', $desiredTable->qualifiedName()),
                    sql: $desiredTable->createSql,
                );
                $this->appendMissingIndexActions($desiredTable, $actions, $desired->driver);
                $this->appendMissingForeignKeyActions($desiredTable, $actions, $desired->driver);
                continue;
            }

            $actualColumns = [];
            foreach ($existing->columns as $column) {
                $actualColumns[strtolower($column->name)] = $column;
            }

            foreach ($desiredTable->columns as $desiredColumn) {
                $current = $actualColumns[strtolower($desiredColumn->name)] ?? null;

                if (!$current instanceof SchemaColumn) {
                    $actions[] = new SchemaDiffAction(
                        kind: 'add_column',
                        table: $desiredTable->qualifiedName(),
                        column: $desiredColumn->name,
                        message: sprintf('Add missing column [%s.%s].', $desiredTable->qualifiedName(), $desiredColumn->name),
                        sql: sprintf(
                            'ALTER TABLE %s ADD COLUMN %s',
                            $this->quoteQualifiedIdentifier($desiredTable->schemaName, $desiredTable->name, $desired->driver),
                            $this->columnSql($desiredColumn, $desired->driver),
                        ),
                    );
                    continue;
                }

                if ($this->columnsDiffer($current, $desiredColumn)) {
                    $actions[] = new SchemaDiffAction(
                        kind: 'modify_column',
                        table: $desiredTable->qualifiedName(),
                        column: $desiredColumn->name,
                        message: sprintf('Column [%s.%s] differs: %s.', $desiredTable->qualifiedName(), $desiredColumn->name, $this->describeColumnDifference($current, $desiredColumn)),
                        sql: null,
                    );
                }
            }

            if ($existing->primaryKey !== $desiredTable->primaryKey) {
                $actions[] = new SchemaDiffAction(
                    kind: 'modify_primary_key',
                    table: $desiredTable->qualifiedName(),
                    column: null,
                    message: sprintf(
                        'Primary key for [%s] differs (actual=%s, desired=%s).',
                        $desiredTable->qualifiedName(),
                        $existing->primaryKey === [] ? '-' : implode(',', $existing->primaryKey),
                        $desiredTable->primaryKey === [] ? '-' : implode(',', $desiredTable->primaryKey),
                    ),
                    sql: null,
                );
            }

            $this->appendMissingIndexActions($desiredTable, $actions, $desired->driver, $existing);
            $this->appendMissingForeignKeyActions($desiredTable, $actions, $desired->driver, $existing);
        }

        return new SchemaDiffReport($actual, $desired, $actions);
    }

    private function columnsDiffer(SchemaColumn $actual, SchemaColumn $desired): bool
    {
        $actualComparableType = $this->comparableType($actual);
        $desiredComparableType = $this->comparableType($desired);

        return $actualComparableType !== $desiredComparableType
            || $actual->length !== $desired->length
            || $actual->precision !== $desired->precision
            || $actual->scale !== $desired->scale
            || $actual->nullable !== $desired->nullable
            || $this->normalizeDefaultComparable($actual->defaultValue) !== $this->normalizeDefaultComparable($desired->defaultValue)
            || $actual->primaryKey !== $desired->primaryKey
            || $actual->autoIncrement !== $desired->autoIncrement;
    }

    private function comparableType(SchemaColumn $column): string
    {
        $type = $column->portableType ?? $column->nativeType;
        $type = strtolower(trim($type));

        return match ($type) {
            'bool', 'boolean' => 'boolean',
            'int', 'int4', 'integer', 'serial' => 'integer',
            'int2', 'smallint' => 'smallint',
            'int8', 'bigint', 'bigserial' => 'bigint',
            'float', 'float4', 'real' => 'float',
            'float8', 'double', 'double precision' => 'double',
            'numeric', 'decimal' => 'decimal',
            'character varying', 'varchar' => 'varchar',
            'character', 'char' => 'char',
            'datetime', 'timestamp', 'timestamptz', 'timestamp without time zone', 'timestamp with time zone' => 'timestamp',
            'jsonb', 'json' => 'json',
            'bytea', 'blob' => 'blob',
            default => $type,
        };
    }

    private function describeColumnDifference(SchemaColumn $actual, SchemaColumn $desired): string
    {
        $changes = [];

        if ($this->comparableType($actual) !== $this->comparableType($desired)) {
            $changes[] = sprintf('type actual=%s desired=%s', $actual->nativeType, $desired->nativeType);
        }
        if ($actual->length !== $desired->length) {
            $changes[] = sprintf('length actual=%s desired=%s', $actual->length ?? '-', $desired->length ?? '-');
        }
        if ($actual->precision !== $desired->precision || $actual->scale !== $desired->scale) {
            $changes[] = sprintf(
                'precision/scale actual=%s/%s desired=%s/%s',
                $actual->precision ?? '-',
                $actual->scale ?? '-',
                $desired->precision ?? '-',
                $desired->scale ?? '-',
            );
        }
        if ($actual->nullable !== $desired->nullable) {
            $changes[] = sprintf('nullable actual=%s desired=%s', $actual->nullable ? 'yes' : 'no', $desired->nullable ? 'yes' : 'no');
        }
        if ($this->normalizeDefaultComparable($actual->defaultValue) !== $this->normalizeDefaultComparable($desired->defaultValue)) {
            $changes[] = sprintf(
                'default actual=%s desired=%s',
                $this->normalizeDefaultComparable($actual->defaultValue) ?? 'null',
                $this->normalizeDefaultComparable($desired->defaultValue) ?? 'null',
            );
        }
        if ($actual->primaryKey !== $desired->primaryKey) {
            $changes[] = sprintf('primary actual=%s desired=%s', $actual->primaryKey ? 'yes' : 'no', $desired->primaryKey ? 'yes' : 'no');
        }
        if ($actual->autoIncrement !== $desired->autoIncrement) {
            $changes[] = sprintf('auto_increment actual=%s desired=%s', $actual->autoIncrement ? 'yes' : 'no', $desired->autoIncrement ? 'yes' : 'no');
        }

        return implode(', ', $changes);
    }

    private function appendMissingIndexActions(
        SchemaTable $desiredTable,
        array &$actions,
        string $driver,
        ?SchemaTable $actualTable = null,
    ): void {
        foreach ($desiredTable->indexes as $desiredIndex) {
            if ($desiredIndex->primary) {
                continue;
            }

            if ($actualTable instanceof SchemaTable && $this->hasEquivalentIndex($actualTable, $desiredIndex)) {
                continue;
            }

            $actions[] = new SchemaDiffAction(
                kind: 'create_index',
                table: $desiredTable->qualifiedName(),
                column: null,
                message: sprintf(
                    'Create missing %sindex [%s] on [%s].',
                    $desiredIndex->unique ? 'unique ' : '',
                    $desiredIndex->name,
                    $desiredTable->qualifiedName(),
                ),
                sql: $this->createIndexSql($desiredTable, $desiredIndex, $driver),
            );
        }
    }

    private function appendMissingForeignKeyActions(
        SchemaTable $desiredTable,
        array &$actions,
        string $driver,
        ?SchemaTable $actualTable = null,
    ): void {
        foreach ($desiredTable->foreignKeys as $desiredForeignKey) {
            if ($actualTable instanceof SchemaTable && $this->hasEquivalentForeignKey($actualTable, $desiredForeignKey)) {
                continue;
            }

            $actions[] = new SchemaDiffAction(
                kind: 'add_foreign_key',
                table: $desiredTable->qualifiedName(),
                column: implode(',', $desiredForeignKey->columns),
                message: sprintf('Add missing foreign key [%s] on [%s].', $desiredForeignKey->name, $desiredTable->qualifiedName()),
                sql: $this->addForeignKeySql($desiredTable, $desiredForeignKey, $driver),
            );
        }
    }

    private function hasEquivalentIndex(SchemaTable $actualTable, SchemaIndex $desiredIndex): bool
    {
        foreach ($actualTable->indexes as $actualIndex) {
            if ($this->indexSignature($actualIndex) === $this->indexSignature($desiredIndex)) {
                return true;
            }
        }

        return false;
    }

    private function hasEquivalentForeignKey(SchemaTable $actualTable, SchemaForeignKey $desiredForeignKey): bool
    {
        foreach ($actualTable->foreignKeys as $actualForeignKey) {
            if ($this->foreignKeySignature($actualForeignKey) === $this->foreignKeySignature($desiredForeignKey)) {
                return true;
            }
        }

        return false;
    }

    private function indexSignature(SchemaIndex $index): string
    {
        return implode('|', [
            $index->unique ? '1' : '0',
            $index->primary ? '1' : '0',
            implode(',', array_map('strtolower', $index->columns)),
        ]);
    }

    private function foreignKeySignature(SchemaForeignKey $foreignKey): string
    {
        return implode('|', [
            implode(',', array_map('strtolower', $foreignKey->columns)),
            strtolower(($foreignKey->referencedSchema ?? '') . '.' . $foreignKey->referencedTable),
            implode(',', array_map('strtolower', $foreignKey->referencedColumns)),
            strtoupper($foreignKey->onUpdate ?? ''),
            strtoupper($foreignKey->onDelete ?? ''),
        ]);
    }

    private function tableKey(SchemaTable $table): string
    {
        return strtolower(($table->schemaName ?? '') . '.' . $table->name);
    }

    private function normalizeDefaultComparable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return '';
        }

        if (preg_match("/^'(.*)'::[a-z0-9_ ]+$/i", $string, $matches) === 1) {
            $string = "'" . $matches[1] . "'";
        }

        if ((str_starts_with($string, "'") && str_ends_with($string, "'")) || (str_starts_with($string, '"') && str_ends_with($string, '"'))) {
            $inner = substr($string, 1, -1);
            $quote = $string[0];
            $escaped = $quote === "'" ? "''" : '""';
            return str_replace($escaped, $quote, $inner);
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $string) === 1) {
            return $string;
        }

        if (preg_match('/^(null|true|false|current_[a-z_]+|\w+\(.*\))$/i', $string) === 1) {
            return strtoupper(preg_replace('/\s+/', ' ', $string) ?? $string);
        }

        return $string;
    }

    private function columnSql(SchemaColumn $column, string $driver): string
    {
        $parts = [
            $this->quoteIdentifier($column->name, $driver),
            $column->nativeType,
        ];

        if ($column->primaryKey && $column->autoIncrement) {
            $parts[] = match ($driver) {
                'pgsql' => 'GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
                'mysql', 'mariadb' => 'AUTO_INCREMENT PRIMARY KEY',
                default => 'PRIMARY KEY AUTOINCREMENT',
            };
            return implode(' ', $parts);
        }

        if ($column->primaryKey) {
            $parts[] = 'PRIMARY KEY';
        }

        if (!$column->nullable) {
            $parts[] = 'NOT NULL';
        }

        if ($column->defaultValue !== null) {
            $parts[] = 'DEFAULT ' . $this->normalizeDefault($column->defaultValue);
        }

        return implode(' ', $parts);
    }

    private function createIndexSql(SchemaTable $table, SchemaIndex $index, string $driver): string
    {
        $columns = implode(', ', array_map(fn(string $column): string => $this->quoteIdentifier($column, $driver), $index->columns));

        return sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $index->unique ? 'UNIQUE ' : '',
            $this->quoteIdentifier($index->name, $driver),
            $this->quoteQualifiedIdentifier($table->schemaName, $table->name, $driver),
            $columns,
        );
    }

    private function addForeignKeySql(SchemaTable $table, SchemaForeignKey $foreignKey, string $driver): ?string
    {
        if ($driver === 'sqlite') {
            return null;
        }

        $columns = implode(', ', array_map(fn(string $column): string => $this->quoteIdentifier($column, $driver), $foreignKey->columns));
        $referencedColumns = implode(', ', array_map(fn(string $column): string => $this->quoteIdentifier($column, $driver), $foreignKey->referencedColumns));
        $sql = sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
            $this->quoteQualifiedIdentifier($table->schemaName, $table->name, $driver),
            $this->quoteIdentifier($foreignKey->name, $driver),
            $columns,
            $this->quoteQualifiedIdentifier($foreignKey->referencedSchema, $foreignKey->referencedTable, $driver),
            $referencedColumns,
        );

        if ($foreignKey->onDelete !== null && $foreignKey->onDelete !== '') {
            $sql .= ' ON DELETE ' . strtoupper($foreignKey->onDelete);
        }
        if ($foreignKey->onUpdate !== null && $foreignKey->onUpdate !== '') {
            $sql .= ' ON UPDATE ' . strtoupper($foreignKey->onUpdate);
        }

        return $sql;
    }

    private function normalizeDefault(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        $string = (string) $value;
        if (
            str_starts_with($string, "'")
            || str_starts_with($string, '"')
            || preg_match('/^\w+\(.*\)$/', $string) === 1
        ) {
            return $string;
        }

        return "'" . str_replace("'", "''", $string) . "'";
    }

    private function quoteIdentifier(string $identifier, string $driver): string
    {
        $quote = in_array($driver, ['mysql', 'mariadb'], true) ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private function quoteQualifiedIdentifier(?string $schema, string $name, string $driver): string
    {
        if ($schema === null || $schema === '') {
            return $this->quoteIdentifier($name, $driver);
        }

        return $this->quoteIdentifier($schema, $driver) . '.' . $this->quoteIdentifier($name, $driver);
    }
}
