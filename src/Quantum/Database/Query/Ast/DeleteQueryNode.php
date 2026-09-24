<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Ast;

use Quantum\Database\Query\QueryMetadata;

final readonly class DeleteQueryNode
{
    /**
     * @param list<PredicateNode> $predicates
     */
    public function __construct(
        public TableNode $from,
        public array $predicates,
        public QueryMetadata $metadata,
    ) {
    }
}
