<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Model;

use Quantum\Database\Query\QueryInterface;
use Quantum\Database\Query\QueryMetadata;
use Quantum\Database\Query\QueryType;

final readonly class SelectQuery implements QueryInterface
{
    /**
     * @param list<string> $columns
     * @param list<Predicate> $predicates
     * @param list<Ordering> $orderings
     */
    public function __construct(
        public TableReference $from,
        public array $columns = ['*'],
        public array $predicates = [],
        public array $orderings = [],
        public ?int $limit = null,
        public ?int $offset = null,
        public bool $distinct = false,
        public QueryMetadata $queryMetadata = new QueryMetadata(),
    ) {
    }

    public function type(): QueryType
    {
        return QueryType::Select;
    }

    public function metadata(): QueryMetadata
    {
        return $this->queryMetadata;
    }
}
