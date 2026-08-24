<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Migration\MigrationDiscovery;
use Quantum\Database\Migration\MigrationExecutionException;
use Quantum\Database\Migration\MigrationLock;
use Quantum\Database\Migration\MigrationRepository;
use Quantum\Database\Migration\MigrationRunner;
use Quantum\Database\Support\ConnectionRegistry;
use VoltStack\Framework\Application;

final class DbRollbackCommand extends Command
{
    public function name(): string
    {
        return 'db:rollback';
    }

    public function description(): string
    {
        return 'Revierte la última tanda de migraciones o una cantidad específica.';
    }

    public function usage(): string
    {
        return 'db:rollback [--connection=primary] [--pretend] [--step=1]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--connection=' => 'Nombre de la conexión configurada a utilizar.',
            '--pretend' => 'Muestra qué migraciones se revertirían sin ejecutarlas.',
            '--step=' => 'Cantidad de migraciones aplicadas a revertir. Por defecto revierte el último batch.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $runner = $this->makeRunner($app, $this->resolveConnectionName($input));
        $pretend = $input->hasOption('pretend');
        $step = $this->resolvePositiveIntOption($input, 'step');

        try {
            $result = $runner->rollback($pretend, $step);
            $this->renderResult($result, $pretend, $output);

            return 0;
        } catch (MigrationExecutionException $e) {
            $checkpoint = $e->checkpoint;
            $advice = $e->recoveryAdvice();

            $output->error(sprintf(
                'db:rollback failed: failure=%s retryable=%s phase=%s fingerprint=%s batch=%s position=%d/%d completed=%d failed_version=%s failed_migration=%s message=%s',
                $e->failure->value,
                $e->retryable ? 'yes' : 'no',
                $checkpoint->phase,
                $checkpoint->fingerprint,
                $checkpoint->batchNumber !== null ? (string) $checkpoint->batchNumber : 'n/a',
                $checkpoint->failedPosition,
                $checkpoint->plannedCount,
                $checkpoint->completedCount(),
                $checkpoint->failedVersion ?? 'n/a',
                $checkpoint->failedMigration ?? 'n/a',
                $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ));
            $output->error(sprintf(
                'Recovery: strategy=%s summary=%s',
                $advice->strategy,
                $advice->summary,
            ));
            foreach ($advice->recommendedCommands as $command) {
                $output->error(sprintf('  next: %s', $command));
            }
            return 1;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:rollback failed: %s', $e->getMessage()));
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
        $lock = MigrationLock::forConnection(
            locksRoot: $app->storagePath('framework/database/migrations'),
            connectionName: $connectionName,
            connection: $connection,
            repositoryTable: $table,
        );

        return new MigrationRunner(
            $connection,
            new MigrationDiscovery(
                basePath: $app->basePath(),
                paths: is_array($paths) ? array_values($paths) : ['database/migrations'],
                classes: is_array($classes) ? array_values($classes) : [],
            ),
            new MigrationRepository($connection, $table),
            $lock,
        );
    }

    /**
     * @param array{planned:int,rolled_back:list<string>,pretended:list<string>} $result
     */
    private function renderResult(array $result, bool $pretend, Output $output): void
    {
        if ($result['planned'] === 0) {
            $output->writeln('No hay migraciones aplicadas para revertir.');
            return;
        }

        if ($pretend) {
            $output->writeln(sprintf('Plan de rollback: %d migracion(es).', $result['planned']));
            foreach ($result['pretended'] as $version) {
                $output->writeln(sprintf('  - %s', $version));
            }
            return;
        }

        $output->writeln(sprintf('Migraciones revertidas: %d', count($result['rolled_back'])));
        foreach ($result['rolled_back'] as $version) {
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
