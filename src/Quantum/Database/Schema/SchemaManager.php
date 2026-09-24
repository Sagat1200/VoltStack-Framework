<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

use Closure;
use Quantum\Database\Contracts\QueryExecutorInterface;
use Quantum\Database\Execution\CompiledDatabaseCommand;
use Quantum\Database\Execution\DatabaseResultType;
use Quantum\Database\Execution\RuntimeBindingSet;
use Quantum\Database\Schema\Builder\TableBlueprint;
use Quantum\Database\Schema\Compiler\SchemaCompiler;
use Quantum\Database\Schema\Model\DropTableDefinition;

final class SchemaManager
{
    public function __construct(
        private readonly SchemaCompiler $compiler,
        private readonly QueryExecutorInterface $executor,
        private readonly ?string $connectionName = null,
    ) {
    }

    public function connection(string $name): self
    {
        return new self($this->compiler, $this->executor, $name);
    }

    public function create(string $table, Closure $callback, bool $ifNotExists = false): void
    {
        $blueprint = new TableBlueprint($table, $ifNotExists);
        $callback($blueprint);

        $operation = $this->compiler->compileCreateTable($blueprint->toCreateDefinition(), $this->connectionName);

        foreach ($operation->commands as $command) {
            $this->executor->execute($command);
        }
    }

    public function dropIfExists(string $table): void
    {
        $operation = $this->compiler->compileDropTable(new DropTableDefinition($table, true), $this->connectionName);

        foreach ($operation->commands as $command) {
            $this->executor->execute($command);
        }
    }

    public function hasTable(string $table): bool
    {
        $result = $this->executor->execute(
            new CompiledDatabaseCommand(
                sql: 'SELECT name FROM sqlite_master WHERE type = ? AND name = ?',
                connectionName: $this->connectionName,
                resultType: DatabaseResultType::Rows,
            ),
            new RuntimeBindingSet(['table', $table]),
        );

        return $result->rows() !== [];
    }
}
