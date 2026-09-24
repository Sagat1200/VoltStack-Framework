<?php

declare(strict_types=1);

namespace Quantum\Database\Query;

use Quantum\Database\Contracts\QueryExecutorInterface;
use Quantum\Database\Execution\DatabaseResult;
use Quantum\Database\Query\Compiler\QueryCompilerInterface;

final class DatabaseQueryRunner
{
    public function __construct(
        private readonly QueryCompilerInterface $compiler,
        private readonly QueryExecutorInterface $executor,
    ) {
    }

    public function run(QueryInterface $query): DatabaseResult
    {
        $compiled = $this->compiler->compile($query);

        return $this->executor->execute($compiled->command, $compiled->bindings);
    }
}
