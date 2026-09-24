<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Ast;

final readonly class TableNode
{
    public function __construct(
        public string $name,
    ) {
    }
}
