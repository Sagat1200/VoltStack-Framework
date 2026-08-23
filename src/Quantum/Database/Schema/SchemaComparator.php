<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final class SchemaComparator
{
    public function compare(SchemaSnapshot $actual, SchemaSnapshot $desired): SchemaDiffReport
    {
        $actions = [];
        $actualTables = [];
        $desiredTableKeys = [];

        foreach ($actual->tables as $table) {
            $actualTables[$this->tableKey($table)] = $table;
        }

        foreach ($desired->tables as $desiredTable) {
            $desiredTableKeys[$this->tableKey($desiredTable)] = true;
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

            if ($desired->driver === 'sqlite') {
                $sqliteRebuildAction = $this->buildSqliteRebuildAction($existing, $desiredTable, $actualColumns);
                if ($sqliteRebuildAction instanceof SchemaDiffAction) {
                    $actions[] = $sqliteRebuildAction;
                    continue;
                }
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
                    [$modifySql, $rollbackSql] = $this->modifyColumnSql($desiredTable, $current, $desiredColumn, $desired->driver);
                    $actions[] = new SchemaDiffAction(
                        kind: 'modify_column',
                        table: $desiredTable->qualifiedName(),
                        column: $desiredColumn->name,
                        message: sprintf('Column [%s.%s] differs: %s.', $desiredTable->qualifiedName(), $desiredColumn->name, $this->describeColumnDifference($current, $desiredColumn)),
                        sql: $modifySql,
                        rollbackSql: $rollbackSql,
                    );
                }
            }

            if ($existing->primaryKey !== $desiredTable->primaryKey) {
                [$modifyPrimaryKeySql, $rollbackPrimaryKeySql] = $this->modifyPrimaryKeySql($existing, $desiredTable, $desired->driver);
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
                    sql: $modifyPrimaryKeySql,
                    rollbackSql: $rollbackPrimaryKeySql,
                );
            }

            $this->appendObsoleteForeignKeyActions($existing, $desiredTable, $actions, $desired->driver);
            $this->appendObsoleteIndexActions($existing, $desiredTable, $actions, $desired->driver);
            $this->appendObsoleteColumnActions($existing, $desiredTable, $actions, $desired->driver);
            $this->appendMissingIndexActions($desiredTable, $actions, $desired->driver, $existing);
            $this->appendMissingForeignKeyActions($desiredTable, $actions, $desired->driver, $existing);
        }

        foreach ($actual->tables as $actualTable) {
            if (isset($desiredTableKeys[$this->tableKey($actualTable)])) {
                continue;
            }

            $actions[] = new SchemaDiffAction(
                kind: 'drop_table',
                table: $actualTable->qualifiedName(),
                column: null,
                message: sprintf('Drop obsolete table [%s].', $actualTable->qualifiedName()),
                sql: $this->dropTableSql($actualTable, $desired->driver),
                rollbackSql: $actualTable->createSql,
                rollbackSqlBatch: $actualTable->createSql !== null
                    ? $this->recreateTableArtifactsSql($actualTable, $desired->driver)
                    : [],
            );
        }

        return new SchemaDiffReport($actual, $desired, $actions);
    }

    /**
     * @param array<string,SchemaColumn> $actualColumns
     */
    private function buildSqliteRebuildAction(
        SchemaTable $actualTable,
        SchemaTable $desiredTable,
        array $actualColumns,
    ): ?SchemaDiffAction {
        $desiredColumns = [];
        $missingColumns = [];
        $modifiedColumns = [];

        foreach ($desiredTable->columns as $desiredColumn) {
            $desiredColumns[strtolower($desiredColumn->name)] = $desiredColumn;
            $current = $actualColumns[strtolower($desiredColumn->name)] ?? null;
            if (!$current instanceof SchemaColumn) {
                $missingColumns[] = $desiredColumn->name;
                continue;
            }

            if ($this->columnsDiffer($current, $desiredColumn)) {
                $modifiedColumns[] = $desiredColumn->name;
            }
        }

        $extraActualColumns = [];
        foreach ($actualTable->columns as $actualColumn) {
            if (!isset($desiredColumns[strtolower($actualColumn->name)])) {
                $extraActualColumns[] = $actualColumn->name;
            }
        }

        $primaryKeyChanged = $actualTable->primaryKey !== $desiredTable->primaryKey;
        if ($modifiedColumns === [] && $extraActualColumns === [] && !$primaryKeyChanged) {
            return null;
        }

        [$sqlBatch, $rollbackSqlBatch] = $this->sqliteRebuildTableSql($actualTable, $desiredTable);
        $reasonParts = [];
        if ($modifiedColumns !== []) {
            $reasonParts[] = 'modified columns=' . implode(',', $modifiedColumns);
        }
        if ($missingColumns !== []) {
            $reasonParts[] = 'new columns=' . implode(',', $missingColumns);
        }
        if ($extraActualColumns !== []) {
            $reasonParts[] = 'dropped columns=' . implode(',', $extraActualColumns);
        }
        if ($primaryKeyChanged) {
            $reasonParts[] = sprintf(
                'primary key actual=%s desired=%s',
                $actualTable->primaryKey === [] ? '-' : implode(',', $actualTable->primaryKey),
                $desiredTable->primaryKey === [] ? '-' : implode(',', $desiredTable->primaryKey),
            );
        }

        return new SchemaDiffAction(
            kind: 'rebuild_table',
            table: $desiredTable->qualifiedName(),
            column: null,
            message: sprintf('Rebuild SQLite table [%s] to apply schema changes (%s).', $desiredTable->qualifiedName(), implode('; ', $reasonParts)),
            sql: null,
            rollbackSql: null,
            sqlBatch: $sqlBatch,
            rollbackSqlBatch: $rollbackSqlBatch,
            requiresNonTransactional: true,
        );
    }

    private function appendObsoleteColumnActions(
        SchemaTable $actualTable,
        SchemaTable $desiredTable,
        array &$actions,
        string $driver,
    ): void {
        if ($driver === 'sqlite') {
            return;
        }

        $desiredColumns = [];
        foreach ($desiredTable->columns as $desiredColumn) {
            $desiredColumns[strtolower($desiredColumn->name)] = true;
        }

        foreach ($actualTable->columns as $actualColumn) {
            if (isset($desiredColumns[strtolower($actualColumn->name)])) {
                continue;
            }

            $actions[] = new SchemaDiffAction(
                kind: 'drop_column',
                table: $desiredTable->qualifiedName(),
                column: $actualColumn->name,
                message: sprintf('Drop obsolete column [%s.%s].', $desiredTable->qualifiedName(), $actualColumn->name),
                sql: $this->dropColumnSql($desiredTable, $actualColumn, $driver),
                rollbackSql: sprintf(
                    'ALTER TABLE %s ADD COLUMN %s',
                    $this->quoteQualifiedIdentifier($desiredTable->schemaName, $desiredTable->name, $driver),
                    $this->columnSql($actualColumn, $driver),
                ),
            );
        }
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
        if ($actual->autoIncrement !== $desired->autoIncrement) {
            $changes[] = sprintf('auto_increment actual=%s desired=%s', $actual->autoIncrement ? 'yes' : 'no', $desired->autoIncrement ? 'yes' : 'no');
        }

        return implode(', ', $changes);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function modifyColumnSql(
        SchemaTable $table,
        SchemaColumn $actual,
        SchemaColumn $desired,
        string $driver,
    ): array {
        if ($driver === 'sqlite' || $actual->primaryKey !== $desired->primaryKey) {
            return [null, null];
        }

        $forward = match ($driver) {
            'pgsql' => $this->pgsqlModifyColumnSql($table, $actual, $desired, $driver),
            'mysql', 'mariadb' => $this->mysqlModifyColumnSql($table, $desired, $driver),
            default => null,
        };

        $rollback = match ($driver) {
            'pgsql' => $this->pgsqlModifyColumnSql($table, $desired, $actual, $driver),
            'mysql', 'mariadb' => $this->mysqlModifyColumnSql($table, $actual, $driver),
            default => null,
        };

        return [$forward, $rollback];
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function modifyPrimaryKeySql(
        SchemaTable $actualTable,
        SchemaTable $desiredTable,
        string $driver,
    ): array {
        if ($driver === 'sqlite') {
            return [null, null];
        }

        $forward = match ($driver) {
            'pgsql' => $this->pgsqlModifyPrimaryKeySql($actualTable, $desiredTable, $driver),
            'mysql', 'mariadb' => $this->mysqlModifyPrimaryKeySql($actualTable, $desiredTable, $driver),
            default => null,
        };

        $rollback = match ($driver) {
            'pgsql' => $this->pgsqlModifyPrimaryKeySql($desiredTable, $actualTable, $driver),
            'mysql', 'mariadb' => $this->mysqlModifyPrimaryKeySql($desiredTable, $actualTable, $driver),
            default => null,
        };

        return [$forward, $rollback];
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
                rollbackSql: $this->dropIndexSql($desiredTable, $desiredIndex, $driver),
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
                rollbackSql: $this->dropForeignKeySql($desiredTable, $desiredForeignKey, $driver),
            );
        }
    }

    private function appendObsoleteIndexActions(
        SchemaTable $actualTable,
        SchemaTable $desiredTable,
        array &$actions,
        string $driver,
    ): void {
        foreach ($actualTable->indexes as $actualIndex) {
            if ($actualIndex->primary) {
                continue;
            }

            if ($this->desiredContainsIndex($desiredTable, $actualIndex)) {
                continue;
            }

            $actions[] = new SchemaDiffAction(
                kind: 'drop_index',
                table: $desiredTable->qualifiedName(),
                column: null,
                message: sprintf('Drop obsolete index [%s] from [%s].', $actualIndex->name, $desiredTable->qualifiedName()),
                sql: $this->dropIndexSql($desiredTable, $actualIndex, $driver),
                rollbackSql: $this->createIndexSql($desiredTable, $actualIndex, $driver),
            );
        }
    }

    private function appendObsoleteForeignKeyActions(
        SchemaTable $actualTable,
        SchemaTable $desiredTable,
        array &$actions,
        string $driver,
    ): void {
        foreach ($actualTable->foreignKeys as $actualForeignKey) {
            if ($this->desiredContainsForeignKey($desiredTable, $actualForeignKey)) {
                continue;
            }

            $actions[] = new SchemaDiffAction(
                kind: 'drop_foreign_key',
                table: $desiredTable->qualifiedName(),
                column: implode(',', $actualForeignKey->columns),
                message: sprintf('Drop obsolete foreign key [%s] from [%s].', $actualForeignKey->name, $desiredTable->qualifiedName()),
                sql: $this->dropForeignKeySql($desiredTable, $actualForeignKey, $driver),
                rollbackSql: $this->addForeignKeySql($desiredTable, $actualForeignKey, $driver),
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

    private function desiredContainsIndex(SchemaTable $desiredTable, SchemaIndex $actualIndex): bool
    {
        foreach ($desiredTable->indexes as $desiredIndex) {
            if ($this->indexSignature($desiredIndex) === $this->indexSignature($actualIndex)) {
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

    private function desiredContainsForeignKey(SchemaTable $desiredTable, SchemaForeignKey $actualForeignKey): bool
    {
        foreach ($desiredTable->foreignKeys as $desiredForeignKey) {
            if ($this->foreignKeySignature($desiredForeignKey) === $this->foreignKeySignature($actualForeignKey)) {
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

    /**
     * @return array{0:list<string>,1:list<string>}
     */
    private function sqliteRebuildTableSql(SchemaTable $actualTable, SchemaTable $desiredTable): array
    {
        $tempName = '__vs_rebuild_' . $desiredTable->name;
        $forward = [
            'PRAGMA foreign_keys = OFF',
            sprintf(
                'ALTER TABLE %s RENAME TO %s',
                $this->quoteIdentifier($desiredTable->name, 'sqlite'),
                $this->quoteIdentifier($tempName, 'sqlite'),
            ),
            $this->sqliteCreateTableSql($desiredTable),
        ];

        $commonForwardColumns = $this->commonColumnNames($actualTable, $desiredTable);
        if ($commonForwardColumns !== []) {
            $forward[] = sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $this->quoteIdentifier($desiredTable->name, 'sqlite'),
                $this->quotedColumnList($commonForwardColumns, 'sqlite'),
                $this->quotedColumnList($commonForwardColumns, 'sqlite'),
                $this->quoteIdentifier($tempName, 'sqlite'),
            );
        }

        $forward[] = sprintf('DROP TABLE %s', $this->quoteIdentifier($tempName, 'sqlite'));
        foreach ($desiredTable->indexes as $index) {
            if ($index->primary) {
                continue;
            }
            $forward[] = $this->createIndexSql($desiredTable, $index, 'sqlite');
        }
        $forward[] = 'PRAGMA foreign_keys = ON';

        $rollbackTempName = '__vs_rebuild_' . $actualTable->name;
        $rollback = [
            'PRAGMA foreign_keys = OFF',
            sprintf(
                'ALTER TABLE %s RENAME TO %s',
                $this->quoteIdentifier($actualTable->name, 'sqlite'),
                $this->quoteIdentifier($rollbackTempName, 'sqlite'),
            ),
            $this->sqliteCreateTableSql($actualTable),
        ];

        $commonRollbackColumns = $this->commonColumnNames($desiredTable, $actualTable);
        if ($commonRollbackColumns !== []) {
            $rollback[] = sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $this->quoteIdentifier($actualTable->name, 'sqlite'),
                $this->quotedColumnList($commonRollbackColumns, 'sqlite'),
                $this->quotedColumnList($commonRollbackColumns, 'sqlite'),
                $this->quoteIdentifier($rollbackTempName, 'sqlite'),
            );
        }

        $rollback[] = sprintf('DROP TABLE %s', $this->quoteIdentifier($rollbackTempName, 'sqlite'));
        foreach ($actualTable->indexes as $index) {
            if ($index->primary) {
                continue;
            }
            $rollback[] = $this->createIndexSql($actualTable, $index, 'sqlite');
        }
        $rollback[] = 'PRAGMA foreign_keys = ON';

        return [$forward, $rollback];
    }

    private function sqliteCreateTableSql(SchemaTable $table): string
    {
        $parts = [];
        $inlinePrimaryKey = count($table->primaryKey) === 1 ? strtolower($table->primaryKey[0]) : null;

        foreach ($table->columns as $column) {
            $parts[] = $this->sqliteColumnSql($column, $inlinePrimaryKey !== null && strtolower($column->name) === $inlinePrimaryKey);
        }

        if (count($table->primaryKey) > 1) {
            $parts[] = 'PRIMARY KEY (' . $this->quotedColumnList($table->primaryKey, 'sqlite') . ')';
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $clause = sprintf(
                'FOREIGN KEY (%s) REFERENCES %s (%s)',
                $this->quotedColumnList($foreignKey->columns, 'sqlite'),
                $this->quoteIdentifier($foreignKey->referencedTable, 'sqlite'),
                $this->quotedColumnList($foreignKey->referencedColumns, 'sqlite'),
            );

            if ($foreignKey->onDelete !== null && $foreignKey->onDelete !== '') {
                $clause .= ' ON DELETE ' . strtoupper($foreignKey->onDelete);
            }
            if ($foreignKey->onUpdate !== null && $foreignKey->onUpdate !== '') {
                $clause .= ' ON UPDATE ' . strtoupper($foreignKey->onUpdate);
            }

            $parts[] = $clause;
        }

        return sprintf(
            'CREATE TABLE %s (%s)',
            $this->quoteIdentifier($table->name, 'sqlite'),
            implode(', ', $parts),
        );
    }

    private function sqliteColumnSql(SchemaColumn $column, bool $inlinePrimaryKey): string
    {
        $parts = [
            $this->quoteIdentifier($column->name, 'sqlite'),
            $column->nativeType,
        ];

        if ($inlinePrimaryKey && $column->autoIncrement) {
            $parts[] = 'PRIMARY KEY AUTOINCREMENT';
            return implode(' ', $parts);
        }

        if ($inlinePrimaryKey) {
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

    /**
     * @return list<string>
     */
    private function commonColumnNames(SchemaTable $source, SchemaTable $target): array
    {
        $targetMap = [];
        foreach ($target->columns as $column) {
            $targetMap[strtolower($column->name)] = true;
        }

        $common = [];
        foreach ($source->columns as $column) {
            if (isset($targetMap[strtolower($column->name)])) {
                $common[] = $column->name;
            }
        }

        return $common;
    }

    private function pgsqlModifyColumnSql(
        SchemaTable $table,
        SchemaColumn $actual,
        SchemaColumn $desired,
        string $driver,
    ): ?string {
        $clauses = [];
        $quotedColumn = $this->quoteIdentifier($desired->name, $driver);

        if (
            $this->comparableType($actual) !== $this->comparableType($desired)
            || $actual->length !== $desired->length
            || $actual->precision !== $desired->precision
            || $actual->scale !== $desired->scale
        ) {
            $clauses[] = sprintf('ALTER COLUMN %s TYPE %s', $quotedColumn, $desired->nativeType);
        }

        if ($actual->nullable !== $desired->nullable) {
            $clauses[] = sprintf(
                'ALTER COLUMN %s %s NOT NULL',
                $quotedColumn,
                $desired->nullable ? 'DROP' : 'SET',
            );
        }

        if ($this->normalizeDefaultComparable($actual->defaultValue) !== $this->normalizeDefaultComparable($desired->defaultValue)) {
            $clauses[] = sprintf(
                'ALTER COLUMN %s %s',
                $quotedColumn,
                $desired->defaultValue === null
                    ? 'DROP DEFAULT'
                    : 'SET DEFAULT ' . $this->normalizeDefault($desired->defaultValue),
            );
        }

        if ($actual->autoIncrement !== $desired->autoIncrement) {
            $clauses[] = sprintf(
                'ALTER COLUMN %s %s',
                $quotedColumn,
                $desired->autoIncrement
                    ? 'ADD GENERATED BY DEFAULT AS IDENTITY'
                    : 'DROP IDENTITY IF EXISTS',
            );
        }

        if ($clauses === []) {
            return null;
        }

        return sprintf(
            'ALTER TABLE %s %s',
            $this->quoteQualifiedIdentifier($table->schemaName, $table->name, $driver),
            implode(', ', $clauses),
        );
    }

    private function mysqlModifyColumnSql(
        SchemaTable $table,
        SchemaColumn $column,
        string $driver,
    ): ?string {
        return sprintf(
            'ALTER TABLE %s MODIFY COLUMN %s',
            $this->quoteQualifiedIdentifier($table->schemaName, $table->name, $driver),
            $this->mysqlColumnDefinition($column, $driver),
        );
    }

    private function pgsqlModifyPrimaryKeySql(
        SchemaTable $actualTable,
        SchemaTable $desiredTable,
        string $driver,
    ): ?string {
        $clauses = [];
        $actualName = $actualTable->primaryKeyName ?? $this->defaultPrimaryKeyName($actualTable, $driver);
        $desiredName = $desiredTable->primaryKeyName ?? $this->defaultPrimaryKeyName($desiredTable, $driver);

        if ($actualTable->primaryKey !== [] && $actualName !== null) {
            $clauses[] = 'DROP CONSTRAINT ' . $this->quoteIdentifier($actualName, $driver);
        }

        if ($desiredTable->primaryKey !== [] && $desiredName !== null) {
            $clauses[] = sprintf(
                'ADD CONSTRAINT %s PRIMARY KEY (%s)',
                $this->quoteIdentifier($desiredName, $driver),
                $this->quotedColumnList($desiredTable->primaryKey, $driver),
            );
        }

        if ($clauses === []) {
            return null;
        }

        return sprintf(
            'ALTER TABLE %s %s',
            $this->quoteQualifiedIdentifier($desiredTable->schemaName, $desiredTable->name, $driver),
            implode(', ', $clauses),
        );
    }

    private function mysqlModifyPrimaryKeySql(
        SchemaTable $actualTable,
        SchemaTable $desiredTable,
        string $driver,
    ): ?string {
        $clauses = [];

        if ($actualTable->primaryKey !== []) {
            $clauses[] = 'DROP PRIMARY KEY';
        }

        if ($desiredTable->primaryKey !== []) {
            $clauses[] = 'ADD PRIMARY KEY (' . $this->quotedColumnList($desiredTable->primaryKey, $driver) . ')';
        }

        if ($clauses === []) {
            return null;
        }

        return sprintf(
            'ALTER TABLE %s %s',
            $this->quoteQualifiedIdentifier($desiredTable->schemaName, $desiredTable->name, $driver),
            implode(', ', $clauses),
        );
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

    private function mysqlColumnDefinition(SchemaColumn $column, string $driver): string
    {
        $parts = [
            $this->quoteIdentifier($column->name, $driver),
            $column->nativeType,
            $column->nullable ? 'NULL' : 'NOT NULL',
        ];

        if ($column->defaultValue !== null && !$column->autoIncrement) {
            $parts[] = 'DEFAULT ' . $this->normalizeDefault($column->defaultValue);
        }

        if ($column->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<string> $columns
     */
    private function quotedColumnList(array $columns, string $driver): string
    {
        return implode(', ', array_map(
            fn(string $column): string => $this->quoteIdentifier($column, $driver),
            $columns,
        ));
    }

    private function defaultPrimaryKeyName(SchemaTable $table, string $driver): ?string
    {
        if ($table->primaryKey === []) {
            return null;
        }

        return match ($driver) {
            'pgsql' => $table->name . '_pkey',
            'mysql', 'mariadb' => 'PRIMARY',
            default => null,
        };
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

    private function dropIndexSql(SchemaTable $table, SchemaIndex $index, string $driver): ?string
    {
        return match ($driver) {
            'mysql', 'mariadb' => sprintf(
                'DROP INDEX %s ON %s',
                $this->quoteIdentifier($index->name, $driver),
                $this->quoteQualifiedIdentifier($table->schemaName, $table->name, $driver),
            ),
            default => sprintf(
                'DROP INDEX %s',
                $this->quoteQualifiedIdentifier($table->schemaName, $index->name, $driver),
            ),
        };
    }

    private function dropForeignKeySql(SchemaTable $table, SchemaForeignKey $foreignKey, string $driver): ?string
    {
        if ($driver === 'sqlite') {
            return null;
        }

        return match ($driver) {
            'mysql', 'mariadb' => sprintf(
                'ALTER TABLE %s DROP FOREIGN KEY %s',
                $this->quoteQualifiedIdentifier($table->schemaName, $table->name, $driver),
                $this->quoteIdentifier($foreignKey->name, $driver),
            ),
            default => sprintf(
                'ALTER TABLE %s DROP CONSTRAINT %s',
                $this->quoteQualifiedIdentifier($table->schemaName, $table->name, $driver),
                $this->quoteIdentifier($foreignKey->name, $driver),
            ),
        };
    }

    private function dropColumnSql(SchemaTable $table, SchemaColumn $column, string $driver): ?string
    {
        return sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $this->quoteQualifiedIdentifier($table->schemaName, $table->name, $driver),
            $this->quoteIdentifier($column->name, $driver),
        );
    }

    private function dropTableSql(SchemaTable $table, string $driver): string
    {
        return sprintf(
            'DROP TABLE %s',
            $this->quoteQualifiedIdentifier($table->schemaName, $table->name, $driver),
        );
    }

    /**
     * @return list<string>
     */
    private function recreateTableArtifactsSql(SchemaTable $table, string $driver): array
    {
        $statements = [$table->createSql];

        foreach ($table->indexes as $index) {
            if ($index->primary) {
                continue;
            }

            $statements[] = $this->createIndexSql($table, $index, $driver);
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $sql = $this->addForeignKeySql($table, $foreignKey, $driver);
            if ($sql !== null) {
                $statements[] = $sql;
            }
        }

        return array_values(array_filter($statements, static fn(?string $statement): bool => $statement !== null && trim($statement) !== ''));
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
        if (in_array(strtoupper($string), ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'], true)) {
            return strtoupper($string);
        }

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