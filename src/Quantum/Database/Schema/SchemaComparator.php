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
            $actualTables[$table->name] = $table;
        }

        foreach ($desired->tables as $desiredTable) {
            $existing = $actualTables[$desiredTable->name] ?? null;

            if (!$existing instanceof SchemaTable) {
                $actions[] = new SchemaDiffAction(
                    kind: 'create_table',
                    table: $desiredTable->name,
                    column: null,
                    message: sprintf('Create missing table [%s].', $desiredTable->name),
                    sql: $desiredTable->createSql,
                );
                continue;
            }

            $actualColumns = [];
            foreach ($existing->columns as $column) {
                $actualColumns[$column->name] = $column;
            }

            foreach ($desiredTable->columns as $desiredColumn) {
                $current = $actualColumns[$desiredColumn->name] ?? null;

                if (!$current instanceof SchemaColumn) {
                    $actions[] = new SchemaDiffAction(
                        kind: 'add_column',
                        table: $desiredTable->name,
                        column: $desiredColumn->name,
                        message: sprintf('Add missing column [%s.%s].', $desiredTable->name, $desiredColumn->name),
                        sql: sprintf(
                            'ALTER TABLE %s ADD COLUMN %s',
                            $this->quoteIdentifier($desiredTable->name),
                            $this->columnSql($desiredColumn),
                        ),
                    );
                    continue;
                }

                if ($this->columnsDiffer($current, $desiredColumn)) {
                    $actions[] = new SchemaDiffAction(
                        kind: 'modify_column',
                        table: $desiredTable->name,
                        column: $desiredColumn->name,
                        message: sprintf(
                            'Column [%s.%s] differs (actual=%s%s, desired=%s%s).',
                            $desiredTable->name,
                            $desiredColumn->name,
                            $current->nativeType,
                            $current->nullable ? ' NULL' : ' NOT NULL',
                            $desiredColumn->nativeType,
                            $desiredColumn->nullable ? ' NULL' : ' NOT NULL',
                        ),
                        sql: null,
                    );
                }
            }
        }

        return new SchemaDiffReport($actual, $desired, $actions);
    }

    private function columnsDiffer(SchemaColumn $actual, SchemaColumn $desired): bool
    {
        return strtoupper($actual->nativeType) !== strtoupper($desired->nativeType)
            || $actual->nullable !== $desired->nullable
            || (string) $actual->defaultValue !== (string) $desired->defaultValue
            || $actual->primaryKey !== $desired->primaryKey
            || $actual->autoIncrement !== $desired->autoIncrement;
    }

    private function columnSql(SchemaColumn $column): string
    {
        $parts = [
            $this->quoteIdentifier($column->name),
            $column->nativeType,
        ];

        if ($column->primaryKey && $column->autoIncrement) {
            $parts[] = 'PRIMARY KEY AUTOINCREMENT';
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

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
