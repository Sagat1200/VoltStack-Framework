<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Http\Request;
use VoltStack\Runtime\Context\ScopeManager;

final class DatabaseStatusCommand extends Command
{
    public function name(): string
    {
        return 'database:status';
    }

    public function description(): string
    {
        return 'Muestra el estado del subsistema Database y la conexion seleccionada.';
    }

    public function usage(): string
    {
        return 'database:status [--connection=name]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--connection=' => 'Consulta una conexion especifica en lugar de la conexion por defecto.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/_cli/database/status', 'GET'));

        try {
            $connection = $this->resolveConnection($input);
            $status = $app->make(DatabaseInterface::class)->status($connection);
        } finally {
            $scope->end();
        }

        $output->writeln('Database status');
        $output->writeln(sprintf('  Default connection: %s', $status->defaultConnectionName));
        $output->writeln(sprintf('  Selected connection: %s', $status->connectionName));
        $output->writeln(sprintf('  Driver: %s', $status->driver));
        $output->writeln(sprintf('  Platform: %s', $status->platform));
        $output->writeln(sprintf('  Database: %s', $status->database));
        $output->writeln(sprintf('  Telemetry enabled: %s', $status->telemetryEnabled ? 'yes' : 'no'));
        $output->writeln(sprintf('  Migration repository: %s', $status->migrationRepositoryExists ? 'present' : 'missing'));
        $output->writeln(sprintf('  Applied migrations: %d', $status->appliedMigrations));
        $output->writeln(sprintf('  Connection touched: %s', $status->connected ? 'yes' : 'no'));

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
