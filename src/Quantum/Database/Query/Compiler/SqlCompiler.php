<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Compiler;

use PDO;
use Quantum\Database\Connection\ConnectionDefinitionRegistry;
use Quantum\Database\Contracts\DialectInterface;
use Quantum\Database\Dialect\DialectResolver;
use Quantum\Database\Execution\CompiledDatabaseCommand;
use Quantum\Database\Execution\DatabaseResultType;
use Quantum\Database\Execution\RuntimeBindingSet;
use Quantum\Database\Query\Ast\DeleteQueryNode;
use Quantum\Database\Query\Ast\InsertQueryNode;
use Quantum\Database\Query\Ast\PredicateNode;
use Quantum\Database\Query\Ast\QueryAstFactory;
use Quantum\Database\Query\Ast\SelectQueryNode;
use Quantum\Database\Query\Ast\UpdateQueryNode;
use Quantum\Database\Query\QueryInterface;
use RuntimeException;

final class SqlCompiler implements QueryCompilerInterface
{
    public function __construct(
        private readonly QueryAstFactory $astFactory,
        private readonly ConnectionDefinitionRegistry $definitions,
        private readonly DialectResolver $dialects,
    ) {
    }

    public function compile(QueryInterface $query): CompiledQuery
    {
        $ast = $this->astFactory->from($query);
        $connectionName = $query->metadata()->connectionName ?? $this->definitions->defaultConnectionName();
        $definition = $this->definitions->find($connectionName);

        if ($definition === null) {
            throw new RuntimeException(sprintf('Unable to resolve database connection [%s] for query compilation.', $connectionName));
        }

        $dialect = $this->dialects->resolve($definition);

        return match (true) {
            $ast instanceof SelectQueryNode => $this->compileSelect($ast, $dialect, $connectionName),
            $ast instanceof InsertQueryNode => $this->compileInsert($ast, $dialect, $connectionName),
            $ast instanceof UpdateQueryNode => $this->compileUpdate($ast, $dialect, $connectionName),
            $ast instanceof DeleteQueryNode => $this->compileDelete($ast, $dialect, $connectionName),
            default => throw new RuntimeException(sprintf('Unsupported query AST [%s].', $ast::class)),
        };
    }

    private function compileSelect(SelectQueryNode $query, DialectInterface $dialect, string $connectionName): CompiledQuery
    {
        $bindings = [];
        $columns = $query->columns === ['*']
            ? '*'
            : implode(', ', array_map(fn(string $column): string => $this->quoteIdentifierPath($dialect, $column), $query->columns));

        $sql = 'SELECT ';
        $sql .= $query->distinct ? 'DISTINCT ' : '';
        $sql .= $columns;
        $sql .= ' FROM ' . $this->quoteIdentifierPath($dialect, $query->from->name);
        $sql .= $this->compileWhere($query->predicates, $dialect, $bindings);
        $sql .= $this->compileOrderBy($query->orderings, $dialect);

        if ($query->limit !== null) {
            $sql .= ' LIMIT ' . $query->limit;
        }

        if ($query->offset !== null) {
            $sql .= ' OFFSET ' . $query->offset;
        }

        return new CompiledQuery(
            new CompiledDatabaseCommand(
                sql: $sql,
                connectionName: $connectionName,
                resultType: DatabaseResultType::Rows,
                fetchMode: PDO::FETCH_ASSOC,
                metadata: ['query_type' => 'select'],
            ),
            new RuntimeBindingSet($bindings),
        );
    }

    private function compileInsert(InsertQueryNode $query, DialectInterface $dialect, string $connectionName): CompiledQuery
    {
        $columns = array_keys($query->values);
        $bindings = array_values($query->values);

        if ($columns === []) {
            throw new RuntimeException('InsertQuery requires at least one column value.');
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifierPath($dialect, $query->into->name),
            implode(', ', array_map(fn(string $column): string => $this->quoteIdentifierPath($dialect, $column), $columns)),
            implode(', ', array_fill(0, count($columns), $dialect->parameterPlaceholder(0))),
        );

        return new CompiledQuery(
            new CompiledDatabaseCommand(
                sql: $sql,
                connectionName: $connectionName,
                resultType: DatabaseResultType::AffectedRows,
                metadata: ['query_type' => 'insert'],
            ),
            new RuntimeBindingSet($bindings),
        );
    }

    private function compileUpdate(UpdateQueryNode $query, DialectInterface $dialect, string $connectionName): CompiledQuery
    {
        $bindings = [];
        $assignments = [];

        foreach ($query->values as $column => $value) {
            $assignments[] = $this->quoteIdentifierPath($dialect, $column) . ' = ' . $dialect->parameterPlaceholder(count($bindings));
            $bindings[] = $value;
        }

        if ($assignments === []) {
            throw new RuntimeException('UpdateQuery requires at least one assigned value.');
        }

        $sql = sprintf(
            'UPDATE %s SET %s',
            $this->quoteIdentifierPath($dialect, $query->table->name),
            implode(', ', $assignments),
        );

        $sql .= $this->compileWhere($query->predicates, $dialect, $bindings);

        return new CompiledQuery(
            new CompiledDatabaseCommand(
                sql: $sql,
                connectionName: $connectionName,
                resultType: DatabaseResultType::AffectedRows,
                metadata: ['query_type' => 'update'],
            ),
            new RuntimeBindingSet($bindings),
        );
    }

    private function compileDelete(DeleteQueryNode $query, DialectInterface $dialect, string $connectionName): CompiledQuery
    {
        $bindings = [];
        $sql = 'DELETE FROM ' . $this->quoteIdentifierPath($dialect, $query->from->name);
        $sql .= $this->compileWhere($query->predicates, $dialect, $bindings);

        return new CompiledQuery(
            new CompiledDatabaseCommand(
                sql: $sql,
                connectionName: $connectionName,
                resultType: DatabaseResultType::AffectedRows,
                metadata: ['query_type' => 'delete'],
            ),
            new RuntimeBindingSet($bindings),
        );
    }

    /**
     * @param list<PredicateNode> $predicates
     * @param list<mixed> $bindings
     */
    private function compileWhere(array $predicates, DialectInterface $dialect, array &$bindings): string
    {
        if ($predicates === []) {
            return '';
        }

        $segments = [];

        foreach ($predicates as $predicate) {
            $segments[] = $this->quoteIdentifierPath($dialect, $predicate->column) . ' ' . $predicate->operator . ' ' . $dialect->parameterPlaceholder(count($bindings));
            $bindings[] = $predicate->value;
        }

        return ' WHERE ' . implode(' AND ', $segments);
    }

    /**
     * @param list<\Quantum\Database\Query\Ast\OrderingNode> $orderings
     */
    private function compileOrderBy(array $orderings, DialectInterface $dialect): string
    {
        if ($orderings === []) {
            return '';
        }

        return ' ORDER BY ' . implode(', ', array_map(
            fn($ordering): string => $this->quoteIdentifierPath($dialect, $ordering->column) . ' ' . strtoupper($ordering->direction),
            $orderings,
        ));
    }

    private function quoteIdentifierPath(DialectInterface $dialect, string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        return implode('.', array_map(
            fn(string $segment): string => $segment === '*' ? '*' : $dialect->quoteIdentifier($segment),
            explode('.', $identifier),
        ));
    }
}
