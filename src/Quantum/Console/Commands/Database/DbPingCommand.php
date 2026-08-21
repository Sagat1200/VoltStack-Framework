<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Support\ConnectionRegistry;

final class DbPingCommand extends Command
{
    public function name(): string
    {
        return 'db:ping';
    }

    public function description(): string
    {
        return 'Verifica la conexión DBAL configurada y muestra driver/capabilities.';
    }

    public function usage(): string
    {
        return 'db:ping [--connection=primary]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--connection=' => 'Nombre de la conexión configurada a verificar.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        /** @var ConnectionRegistry $registry */
        $registry = $app->make(ConnectionRegistry::class);
        $connectionName = is_string($input->option('connection')) ? (string) $input->option('connection') : null;

        try {
            $connection = $registry->connection($connectionName);
            $connection->connect();
            $ok = $connection->ping();
            $info = $connection->getDriverInfo();
            $caps = $connection->getCapabilities();

            $output->writeln('VoltStack Database Ping');
            $output->writeln(sprintf('  Connection: %s', $connectionName ?? $registry->defaultConnectionName()));
            $output->writeln(sprintf('  Status: %s', $ok ? 'OK' : 'FAIL'));
            $output->writeln(sprintf('  Driver: %s', $info->driverName));
            $output->writeln(sprintf('  Version: %s', $info->serverVersion));
            $output->writeln(sprintf('  Database: %s', $info->databaseName));
            $output->writeln(sprintf('  Savepoints: %s', $caps->savepoints ? 'yes' : 'no'));
            $output->writeln(sprintf('  Returning: %s', $caps->returningClause ? 'yes' : 'no'));
            $output->writeln(sprintf('  Quote style: %s', $caps->quoteStyle));
            $output->writeln(sprintf('  Param style: %s', $caps->paramStyle));

            return $ok ? 0 : 1;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:ping failed: %s', $e->getMessage()));
            return 1;
        }
    }
}
