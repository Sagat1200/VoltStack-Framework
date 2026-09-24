<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Model;

final readonly class Predicate
{
    public function __construct(
        public string $column,
        public string $operator,
        public mixed $value,
    ) {
    }
}
