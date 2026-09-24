<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Model;

final readonly class TableReference
{
    public function __construct(
        public string $name,
    ) {
    }
}
