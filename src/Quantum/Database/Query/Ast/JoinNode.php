<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Ast;

final readonly class JoinNode
{
    public function __construct(
        public string $type,
        public TableNode $table,
        public string $leftColumn,
        public string $operator,
        public string $rightColumn,
    ) {
    }
}
