<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Model;

final readonly class Ordering
{
    public function __construct(
        public string $column,
        public string $direction = 'asc',
    ) {
    }
}
