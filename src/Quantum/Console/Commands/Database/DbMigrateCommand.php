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

final class DbMigrateCommand extends Command
{
    public function name(): string
    {
        return 'db:migrate';
    }

    public function description(): string
    {
        return 'Ejecuta migraciones pendientes y registra el historial aplicado.';
    }

    public function usage(): string
    {
        return 'db:migrate [--connection=primary] [--pretend] [--step=1]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--connection=' => 'Nombre de la conexión configurada a utilizar.',
            '--pretend' => 'Muestra qué migraciones se ejecutarían sin aplicarlas.',
            '--step=' => 'Limita la cantidad de migraciones pendientes a ejecutar.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $runner = $this->makeRunner($app, $this->resolveConnectionName($input));
        $pretend = $input->hasOption('pretend');
        $step = $this->resolvePositiveIntOption($input, 'step');

        try {
            $result = $runner->migrate($pretend, $step);
            $this->renderResult($result, $pretend, $output);

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:migrate failed: %s', $e->getMessage()));
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

    /**
     * @param array{planned:int,applied:list<string>,pretended:list<string>} $result
     */
    private function renderResult(array $result, bool $pretend, Output $output): void
    {
        if ($result['planned'] === 0) {
            $output->writeln('No hay migraciones pendientes.');
            return;
        }

        if ($pretend) {
            $output->writeln(sprintf('Plan de migración: %d pendiente(s).', $result['planned']));
            foreach ($result['pretended'] as $version) {
                $output->writeln(sprintf('  - %s', $version));
            }
            return;
        }

        $output->writeln(sprintf('Migraciones aplicadas: %d', count($result['applied'])));
        foreach ($result['applied'] as $version) {
            $output->writeln(sprintf('  - %s', $version));
        }
    }

    private function resolveConnectionName(Input $input): ?string
    {
        return is_string($input->option('connection')) ? (string) $input->option('connection') : null;
    }

    private function resolvePositiveIntOption(Input $input, string $option): ?int
    {
        $value = $input->option($option);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }
}
