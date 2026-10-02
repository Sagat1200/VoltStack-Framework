<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Ast;

use Quantum\Database\Query\Model\DeleteQuery;
use Quantum\Database\Query\Model\InsertQuery;
use Quantum\Database\Query\Model\Join;
use Quantum\Database\Query\Model\Ordering;
use Quantum\Database\Query\Model\Predicate;
use Quantum\Database\Query\Model\SelectQuery;
use Quantum\Database\Query\Model\TableReference;
use Quantum\Database\Query\Model\UpdateQuery;
use Quantum\Database\Query\QueryInterface;
use RuntimeException;

final class QueryAstFactory
{
    public function from(QueryInterface $query): object
    {
        return match (true) {
            $query instanceof SelectQuery => $this->select($query),
            $query instanceof InsertQuery => $this->insert($query),
            $query instanceof UpdateQuery => $this->update($query),
            $query instanceof DeleteQuery => $this->delete($query),
            default => throw new RuntimeException(sprintf('Unsupported query model [%s].', $query::class)),
        };
    }

    public function select(SelectQuery $query): SelectQueryNode
    {
        return new SelectQueryNode(
            from: $this->table($query->from),
            columns: $query->columns,
            joins: array_map($this->join(...), $query->joins),
            predicates: array_map($this->predicate(...), $query->predicates),
            orderings: array_map($this->ordering(...), $query->orderings),
            limit: $query->limit,
            offset: $query->offset,
            distinct: $query->distinct,
            metadata: $query->metadata(),
        );
    }

    public function insert(InsertQuery $query): InsertQueryNode
    {
        return new InsertQueryNode(
            into: $this->table($query->into),
            values: $query->values,
            metadata: $query->metadata(),
        );
    }

    public function update(UpdateQuery $query): UpdateQueryNode
    {
        return new UpdateQueryNode(
            table: $this->table($query->table),
            values: $query->values,
            predicates: array_map($this->predicate(...), $query->predicates),
            metadata: $query->metadata(),
        );
    }

    public function delete(DeleteQuery $query): DeleteQueryNode
    {
        return new DeleteQueryNode(
            from: $this->table($query->from),
            predicates: array_map($this->predicate(...), $query->predicates),
            metadata: $query->metadata(),
        );
    }

    private function table(TableReference $table): TableNode
    {
        return new TableNode($table->name, $table->alias);
    }

    private function join(Join $join): JoinNode
    {
        return new JoinNode(
            type: $join->type,
            table: $this->table($join->table),
            leftColumn: $join->leftColumn,
            operator: $join->operator,
            rightColumn: $join->rightColumn,
        );
    }

    private function predicate(Predicate $predicate): PredicateNode
    {
        return new PredicateNode($predicate->column, $predicate->operator, $predicate->value);
    }

    private function ordering(Ordering $ordering): OrderingNode
    {
        return new OrderingNode($ordering->column, $ordering->direction);
    }
}
