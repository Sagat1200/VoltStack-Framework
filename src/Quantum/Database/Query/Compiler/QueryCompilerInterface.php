<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Compiler;

use Quantum\Database\Query\QueryInterface;

interface QueryCompilerInterface
{
    public function compile(QueryInterface $query): CompiledQuery;
}
