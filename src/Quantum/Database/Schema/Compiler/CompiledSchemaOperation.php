<?php

declare(strict_types=1);

namespace Quantum\Database\Schema\Compiler;

use Quantum\Database\Execution\CompiledDatabaseCommand;

final readonly class CompiledSchemaOperation
{
    /**
     * @param list<CompiledDatabaseCommand> $commands
     */
    public function __construct(
        public array $commands,
    ) {
    }
}
