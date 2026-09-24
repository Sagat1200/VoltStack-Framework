<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Ast;

use Quantum\Database\Query\QueryMetadata;

final readonly class UpdateQueryNode
{
    /**
     * @param array<string, mixed> $values
     * @param list<PredicateNode> $predicates
     */
    public function __construct(
        public TableNode $table,
        public array $values,
        public array $predicates,
        public QueryMetadata $metadata,
    ) {
    }
}
