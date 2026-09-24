<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Ast;

use Quantum\Database\Query\QueryMetadata;

final readonly class SelectQueryNode
{
    /**
     * @param list<string> $columns
     * @param list<PredicateNode> $predicates
     * @param list<OrderingNode> $orderings
     */
    public function __construct(
        public TableNode $from,
        public array $columns,
        public array $predicates,
        public array $orderings,
        public ?int $limit,
        public ?int $offset,
        public bool $distinct,
        public QueryMetadata $metadata,
    ) {
    }
}
