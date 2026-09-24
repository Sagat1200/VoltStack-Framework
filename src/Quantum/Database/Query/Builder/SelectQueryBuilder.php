<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Builder;

use Quantum\Database\Execution\DatabaseResult;
use Quantum\Database\Query\Compiler\CompiledQuery;
use Quantum\Database\Query\DatabaseQueryRunner;
use Quantum\Database\Query\Model\DeleteQuery;
use Quantum\Database\Query\Model\InsertQuery;
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
     * @var list<Ordering>
     */
    private array $orderings = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $distinct = false;

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
            from: new TableReference($this->table),
            columns: $this->columns,
            predicates: $this->predicates,
            orderings: $this->orderings,
            limit: 1,
            offset: $this->offset,
            distinct: $this->distinct,
            queryMetadata: $this->metadata(),
        );

        return $this->runner->run($query)->first();
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
            from: new TableReference($this->table),
            columns: $this->columns,
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
}
