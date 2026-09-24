<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

use Quantum\Database\Execution\CompiledDatabaseCommand;
use Quantum\Database\Execution\DatabaseResult;
use Quantum\Database\Execution\ExecutionContext;
use Quantum\Database\Execution\RuntimeBindingSet;

interface QueryExecutorInterface
{
    public function execute(
        CompiledDatabaseCommand $command,
        RuntimeBindingSet $bindings = new RuntimeBindingSet(),
        ?ExecutionContext $context = null,
    ): DatabaseResult;
}
