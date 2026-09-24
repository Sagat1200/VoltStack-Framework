<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Http\Request;
use VoltStack\Runtime\Context\ScopeManager;

final class DatabaseRollbackCommand extends Command
{
    public function name(): string
    {
        return 'database:rollback';
    }

    public function description(): string
    {
        return 'Revierte el ultimo batch de migraciones del subsistema Database.';
    }

    public function usage(): string
    {
        return 'database:rollback [path] [--connection=name]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function argumentsHelp(): array
    {
        return [
            'path' => 'Ruta opcional al directorio de migraciones. Si se omite, se usa database/migrations.',
        ];
    }

    public function optionsHelp(): array
    {
        return [
            '--connection=' => 'Usa una conexion especifica para revertir el ultimo batch.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/_cli/database/rollback', 'POST'));

        try {
            $path = $input->arguments()[0] ?? null;
            $connection = $this->resolveConnection($input);
            $count = $app->make(DatabaseInterface::class)->rollbackLastBatch(
                is_string($path) && trim($path) !== '' ? trim($path) : null,
                $connection,
            );
        } finally {
            $scope->end();
        }

        $output->writeln('Database rollback completed.');
        $output->writeln(sprintf('  Connection: %s', $connection ?? 'default'));
        $output->writeln(sprintf('  Rolled back migrations: %d', $count));

        return 0;
    }

    private function resolveConnection(Input $input): ?string
    {
        $option = $input->option('connection');

        return is_string($option) && trim($option) !== ''
            ? trim($option)
            : null;
    }
}
