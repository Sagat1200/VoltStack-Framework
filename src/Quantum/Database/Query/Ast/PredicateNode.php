<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Ast;

final readonly class PredicateNode
{
    public function __construct(
        public string $column,
        public string $operator,
        public mixed $value,
    ) {
    }
}
