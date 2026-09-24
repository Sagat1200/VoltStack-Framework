<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Model;

use Quantum\Database\Query\QueryInterface;
use Quantum\Database\Query\QueryMetadata;
use Quantum\Database\Query\QueryType;

final readonly class DeleteQuery implements QueryInterface
{
    /**
     * @param list<Predicate> $predicates
     */
    public function __construct(
        public TableReference $from,
        public array $predicates = [],
        public QueryMetadata $queryMetadata = new QueryMetadata(),
    ) {
    }

    public function type(): QueryType
    {
        return QueryType::Delete;
    }

    public function metadata(): QueryMetadata
    {
        return $this->queryMetadata;
    }
}
