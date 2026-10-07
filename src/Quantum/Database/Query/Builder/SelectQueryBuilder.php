<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Builder;

use Quantum\Database\Execution\DatabaseResult;
use Quantum\Database\Query\Compiler\CompiledQuery;
use Quantum\Database\Query\DatabaseQueryRunner;
use Quantum\Database\Query\Model\DeleteQuery;
use Quantum\Database\Query\Model\InsertQuery;
use Quantum\Database\Query\Model\Join;
use Quantum\Database\Query\Model\Ordering;
use Quantum\Database\Query\Model\Predicate;
use Quantum\Database\Query\Model\SelectQuery;
use Quantum\Database\Query\Model\TableReference;
use Quantum\Database\Query\Model\UpdateQuery;
use Quantum\Database\Query\QueryMetadata;
use Quantum\Database\Query\Compiler\QueryCompilerInterface;

final class SelectQueryBuilder
{
    /**
     * @var list<string>
     */
    private array $columns = ['*'];

    /**
     * @var list<Predicate>
     */
    private array $predicates = [];

    /**
     * @var list<Join>
     */
    private array $joins = [];

    /**
     * @var list<Ordering>
     */
    private array $orderings = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $distinct = false;

    private ?string $alias = null;

    public function __construct(
        private readonly string $table,
        private readonly DatabaseQueryRunner $runner,
        private readonly QueryCompilerInterface $compiler,
        private ?string $connectionName = null,
    ) {
    }

    public function connection(string $name): self
    {
        $this->connectionName = $name;

        return $this;
    }

    public function select(string ...$columns): self
    {
        $this->columns = $columns !== [] ? array_values($columns) : ['*'];

        return $this;
    }

    public function as(string $alias): self
    {
        $normalized = trim($alias);
        $this->alias = $normalized !== '' ? $normalized : null;

        return $this;
    }

    public function distinct(bool $enabled = true): self
    {
        $this->distinct = $enabled;

        return $this;
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = is_string($operatorOrValue) ? trim($operatorOrValue) : '=';
        }

        $this->predicates[] = new Predicate($column, $operator, $value);

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $this->predicates[] = new Predicate($column, 'IN', array_values($values));

        return $this;
    }

    public function join(string $table, string $leftColumn, mixed $operatorOrRightColumn, ?string $rightColumn = null, ?string $alias = null): self
    {
        return $this->addJoin('INNER', $table, $leftColumn, $operatorOrRightColumn, $rightColumn, $alias);
    }

    public function leftJoin(string $table, string $leftColumn, mixed $operatorOrRightColumn, ?string $rightColumn = null, ?string $alias = null): self
    {
        return $this->addJoin('LEFT', $table, $leftColumn, $operatorOrRightColumn, $rightColumn, $alias);
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $normalized = strtolower(trim($direction));
        $this->orderings[] = new Ordering($column, $normalized === 'desc' ? 'desc' : 'asc');

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function get(): DatabaseResult
    {
        return $this->runner->run($this->toSelectQuery());
    }

    public function first(): ?array
    {
        $query = new SelectQuery(
            from: new TableReference($this->table, $this->alias),
            columns: $this->columns,
            joins: $this->joins,
            predicates: $this->predicates,
            orderings: $this->orderings,
            limit: 1,
            offset: $this->offset,
            distinct: $this->distinct,
            queryMetadata: $this->metadata(),
        );

        return $this->runner->run($query)->first();
    }

    public function withoutLimitOffset(): self
    {
        return (new self(
            $this->table,
            $this->runner,
            $this->compiler,
            $this->connectionName,
        ))
            ->select(...$this->columns)
            ->as($this->alias ?? '')
            ->distinct($this->distinct)
            ->joins($this->joins)
            ->wherePredicates($this->predicates)
            ->orderings($this->orderings);
    }

    public function count(?string $column = null): int
    {
        $builder = (new self(
            $this->table,
            $this->runner,
            $this->compiler,
            $this->connectionName,
        ))
            ->as($this->alias ?? '')
            ->joins($this->joins)
            ->wherePredicates($this->predicates);

        if ($column !== null && $column !== '' && $column !== '*') {
            $builder->where($column, '!=', null);
        }

        return count($builder->get());
    }

    /**
     * @param list<Predicate> $predicates
     */
    private function wherePredicates(array $predicates): self
    {
        foreach ($predicates as $predicate) {
            $this->predicates[] = $predicate;
        }

        return $this;
    }

    /**
     * @param list<Join> $joins
     */
    private function joins(array $joins): self
    {
        foreach ($joins as $join) {
            $this->joins[] = $join;
        }

        return $this;
    }

    /**
     * @param list<Ordering> $orderings
     */
    private function orderings(array $orderings): self
    {
        foreach ($orderings as $ordering) {
            $this->orderings[] = $ordering;
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insert(array $values): DatabaseResult
    {
        return $this->runner->run(new InsertQuery(
            into: new TableReference($this->table),
            values: $values,
            queryMetadata: $this->metadata(),
        ));
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values): DatabaseResult
    {
        return $this->runner->run(new UpdateQuery(
            table: new TableReference($this->table),
            values: $values,
            predicates: $this->predicates,
            queryMetadata: $this->metadata(),
        ));
    }

    public function delete(): DatabaseResult
    {
        return $this->runner->run(new DeleteQuery(
            from: new TableReference($this->table),
            predicates: $this->predicates,
            queryMetadata: $this->metadata(),
        ));
    }

    public function toSelectQuery(): SelectQuery
    {
        return new SelectQuery(
            from: new TableReference($this->table, $this->alias),
            columns: $this->columns,
            joins: $this->joins,
            predicates: $this->predicates,
            orderings: $this->orderings,
            limit: $this->limit,
            offset: $this->offset,
            distinct: $this->distinct,
            queryMetadata: $this->metadata(),
        );
    }

    public function compile(): CompiledQuery
    {
        return $this->compiler->compile($this->toSelectQuery());
    }

    private function metadata(): QueryMetadata
    {
        return new QueryMetadata(connectionName: $this->connectionName);
    }

    private function addJoin(string $type, string $table, string $leftColumn, mixed $operatorOrRightColumn, ?string $rightColumn, ?string $alias): self
    {
        $normalizedTable = trim($table);
        if ($normalizedTable === '') {
            throw new \RuntimeException('Join table name cannot be empty.');
        }

        if ($rightColumn === null) {
            $operator = '=';
            $rightColumn = is_string($operatorOrRightColumn) ? trim($operatorOrRightColumn) : '';
        } else {
            $operator = is_string($operatorOrRightColumn) ? trim($operatorOrRightColumn) : '=';
        }

        $normalizedLeft = trim($leftColumn);
        $normalizedRight = trim($rightColumn);
        $normalizedAlias = $alias !== null ? trim($alias) : null;

        if ($normalizedLeft === '' || $normalizedRight === '') {
            throw new \RuntimeException('Join columns cannot be empty.');
        }

        $this->joins[] = new Join(
            type: $type,
            table: new TableReference($normalizedTable, $normalizedAlias !== '' ? $normalizedAlias : null),
            leftColumn: $normalizedLeft,
            operator: $operator !== '' ? $operator : '=',
            rightColumn: $normalizedRight,
        );

        return $this;
    }
}
