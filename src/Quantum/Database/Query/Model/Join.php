<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Model;

final readonly class Join
{
    public function __construct(
        public string $type,
        public TableReference $table,
        public string $leftColumn,
        public string $operator,
        public string $rightColumn,
    ) {
    }
}
