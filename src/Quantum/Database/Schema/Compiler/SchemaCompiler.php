<?php

declare(strict_types=1);

namespace Quantum\Database\Schema\Compiler;

use Quantum\Database\Connection\ConnectionDefinitionRegistry;
use Quantum\Database\Contracts\DialectInterface;
use Quantum\Database\Dialect\DialectResolver;
use Quantum\Database\Execution\CompiledDatabaseCommand;
use Quantum\Database\Execution\DatabaseResultType;
use Quantum\Database\Schema\Model\ColumnDefinition;
use Quantum\Database\Schema\Model\CreateTableDefinition;
use Quantum\Database\Schema\Model\DropTableDefinition;
use RuntimeException;

final class SchemaCompiler
{
    public function __construct(
        private readonly ConnectionDefinitionRegistry $definitions,
        private readonly DialectResolver $dialects,
    ) {
    }

    public function compileCreateTable(CreateTableDefinition $definition, ?string $connectionName = null): CompiledSchemaOperation
    {
        $target = $connectionName ?? $this->definitions->defaultConnectionName();
        $dialect = $this->resolveDialect($target);

        if ($definition->columns === []) {
            throw new RuntimeException(sprintf('Schema create table [%s] requires at least one column.', $definition->table));
        }

        $sql = 'CREATE TABLE ';
        $sql .= $definition->ifNotExists ? 'IF NOT EXISTS ' : '';
        $sql .= $this->quoteIdentifierPath($dialect, $definition->table);
        $sql .= ' (' . implode(', ', array_map(fn(ColumnDefinition $column): string => $this->compileColumn($column, $dialect), $definition->columns)) . ')';

        return new CompiledSchemaOperation([
            new CompiledDatabaseCommand(
                sql: $sql,
                connectionName: $target,
                resultType: DatabaseResultType::NoResult,
                metadata: ['schema_operation' => 'create_table', 'table' => $definition->table],
            ),
        ]);
    }

    public function compileDropTable(DropTableDefinition $definition, ?string $connectionName = null): CompiledSchemaOperation
    {
        $target = $connectionName ?? $this->definitions->defaultConnectionName();
        $dialect = $this->resolveDialect($target);

        $sql = 'DROP TABLE ';
        $sql .= $definition->ifExists ? 'IF EXISTS ' : '';
        $sql .= $this->quoteIdentifierPath($dialect, $definition->table);

        return new CompiledSchemaOperation([
            new CompiledDatabaseCommand(
                sql: $sql,
                connectionName: $target,
                resultType: DatabaseResultType::NoResult,
                metadata: ['schema_operation' => 'drop_table', 'table' => $definition->table],
            ),
        ]);
    }

    private function resolveDialect(string $connectionName): DialectInterface
    {
        $definition = $this->definitions->find($connectionName);

        if ($definition === null) {
            throw new RuntimeException(sprintf('Unable to resolve database connection [%s] for schema compilation.', $connectionName));
        }

        return $this->dialects->resolve($definition);
    }

    private function compileColumn(ColumnDefinition $column, DialectInterface $dialect): string
    {
        if ($column->primary && $column->autoIncrement && $this->isSqlite($dialect)) {
            return $this->quoteIdentifierPath($dialect, $column->name) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sql = $this->quoteIdentifierPath($dialect, $column->name) . ' ' . $this->mapType($column->type);

        if ($column->primary) {
            $sql .= ' PRIMARY KEY';
        }

        if ($column->autoIncrement) {
            $sql .= ' AUTOINCREMENT';
        }

        if (! $column->nullable) {
            $sql .= ' NOT NULL';
        }

        if ($column->unique) {
            $sql .= ' UNIQUE';
        }

        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->renderDefault($column->default);
        }

        return $sql;
    }

    private function mapType(string $type): string
    {
        return match (strtolower($type)) {
            'integer' => 'INTEGER',
            'boolean' => 'INTEGER',
            'timestamp' => 'TEXT',
            'string' => 'TEXT',
            default => strtoupper($type),
        };
    }

    private function renderDefault(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            $value === null => 'NULL',
            default => "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }

    private function isSqlite(DialectInterface $dialect): bool
    {
        return strtolower($dialect->id()) === 'sqlite';
    }

    private function quoteIdentifierPath(DialectInterface $dialect, string $identifier): string
    {
        return implode('.', array_map(
            static fn(string $segment): string => $dialect->quoteIdentifier($segment),
            explode('.', $identifier),
        ));
    }
}
