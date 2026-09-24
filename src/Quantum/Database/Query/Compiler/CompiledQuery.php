<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Compiler;

use Quantum\Database\Execution\CompiledDatabaseCommand;
use Quantum\Database\Execution\RuntimeBindingSet;

final readonly class CompiledQuery
{
    public function __construct(
        public CompiledDatabaseCommand $command,
        public RuntimeBindingSet $bindings,
    ) {
    }
}
