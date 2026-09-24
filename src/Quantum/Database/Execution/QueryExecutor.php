<?php

declare(strict_types=1);

namespace Quantum\Database\Execution;

use Quantum\Database\Contracts\QueryExecutorInterface;
use Quantum\Database\Contracts\StatementExecutorInterface;

final class QueryExecutor implements QueryExecutorInterface
{
    public function __construct(
        private readonly StatementExecutorInterface $statements,
    ) {
    }

    public function execute(
        CompiledDatabaseCommand $command,
        RuntimeBindingSet $bindings = new RuntimeBindingSet(),
        ?ExecutionContext $context = null,
    ): DatabaseResult {
        return $this->statements->execute($command, $bindings, $context);
    }
}
