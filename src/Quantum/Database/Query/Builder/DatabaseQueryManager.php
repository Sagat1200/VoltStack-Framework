<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Builder;

use Quantum\Database\Query\Compiler\QueryCompilerInterface;
use Quantum\Database\Query\DatabaseQueryRunner;

final class DatabaseQueryManager
{
    public function __construct(
        private readonly DatabaseQueryRunner $runner,
        private readonly QueryCompilerInterface $compiler,
    ) {
    }

    public function table(string $table, ?string $connectionName = null): SelectQueryBuilder
    {
        return new SelectQueryBuilder(
            table: $table,
            runner: $this->runner,
            compiler: $this->compiler,
            connectionName: $connectionName,
        );
    }
}
