<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Migration\MigrationDiscovery;
use Quantum\Database\Migration\MigrationRepository;
use Quantum\Database\Migration\MigrationRunner;
use Quantum\Database\Support\ConnectionRegistry;
use VoltStack\Framework\Application;

final class DbMigrateStatusCommand extends Command
{
    public function name(): string
    {
        return 'db:migrate-status';
    }

    public function description(): string
    {
        return 'Muestra el estado de las migraciones descubiertas y su historial aplicado.';
    }

    public function usage(): string
    {
        return 'db:migrate-status [--connection=primary]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--connection=' => 'Nombre de la conexión configurada a utilizar.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $runner = $this->makeRunner($app, $this->resolveConnectionName($input));

        try {
            $rows = $runner->status();
            if ($rows === []) {
                $output->writeln('No se encontraron migraciones.');
                return 0;
            }

            foreach ($rows as $row) {
                $status = $row['applied'] ? 'applied' : 'pending';
                $batch = $row['batch'] === null ? '-' : (string) $row['batch'];
                $executedAt = $row['executed_at'] ?? '-';

                $output->writeln(sprintf(
                    '[%s] %s batch=%s executed_at=%s %s',
                    strtoupper($status),
                    $row['version'],
                    $batch,
                    $executedAt,
                    $row['migration'],
                ));
            }

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:migrate-status failed: %s', $e->getMessage()));
            return 1;
        }
    }

    private function makeRunner(Application $app, ?string $connectionName): MigrationRunner
    {
        /** @var ConnectionRegistry $registry */
        $registry = $app->make(ConnectionRegistry::class);
        $connection = $registry->connection($connectionName);
        $connection->connect();

        $paths = $app->config('database.migrations.paths', ['database/migrations']);
        $classes = $app->config('database.migrations.classes', []);
        $table = (string) $app->config('database.migrations.table', 'framework_migrations');

        return new MigrationRunner(
            $connection,
            new MigrationDiscovery(
                basePath: $app->basePath(),
                paths: is_array($paths) ? array_values($paths) : ['database/migrations'],
                classes: is_array($classes) ? array_values($classes) : [],
            ),
            new MigrationRepository($connection, $table),
        );
    }

    private function resolveConnectionName(Input $input): ?string
    {
        return is_string($input->option('connection')) ? (string) $input->option('connection') : null;
    }
}
