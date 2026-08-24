<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Migration\MigrationDiscovery;
use Quantum\Database\Migration\MigrationExecutionException;
use Quantum\Database\Migration\MigrationLock;
use Quantum\Database\Migration\MigrationPlan;
use Quantum\Database\Migration\MigrationPlanItem;
use Quantum\Database\Migration\MigrationRepository;
use Quantum\Database\Migration\MigrationRunner;
use Quantum\Database\Migration\MigrationVerificationResult;
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
            $plan = $runner->planMigrate($step);

            if (!$plan->hasItems()) {
                $output->writeln('No hay migraciones pendientes.');
                return 0;
            }

            $this->renderPlan($plan, $output);

            if ($pretend) {
                $output->writeln('Dry-run activado: no se aplicaron cambios.');
                return 0;
            }

            $result = $runner->executePlan($plan);
            $this->renderExecutionResult($result, $output);

            return 0;
        } catch (MigrationExecutionException $e) {
            $checkpoint = $e->checkpoint;
            $advice = $e->recoveryAdvice();

            $output->error(sprintf(
                'db:migrate failed: failure=%s retryable=%s phase=%s fingerprint=%s batch=%s position=%d/%d completed=%d failed_version=%s failed_migration=%s message=%s',
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

    private function renderPlan(MigrationPlan $plan, Output $output): void
    {
        $output->writeln(sprintf('Plan de migración: %d pendiente(s).', $plan->plannedCount()));
        $output->writeln(sprintf('Fingerprint: %s', $plan->fingerprint));
        $output->writeln(sprintf(
            'Contexto: driver=%s version=%s historial=%s batch=%s tx=%d non-tx=%d',
            $plan->driver->driverName,
            $plan->driver->serverVersion !== '' ? $plan->driver->serverVersion : 'unknown',
            $plan->repositoryTable,
            $plan->batchNumber !== null ? (string) $plan->batchNumber : 'n/a',
            $plan->transactionalCount(),
            $plan->nonTransactionalCount(),
        ));

        foreach ($plan->items as $item) {
            $this->renderPlanItem($item, $output);
        }
    }

    /**
     * @param array{
     *   planned:int,
     *   applied:list<string>,
     *   verification:MigrationVerificationResult
     * } $result
     */
    private function renderExecutionResult(array $result, Output $output): void
    {
        $output->writeln(sprintf('Migraciones aplicadas: %d', count($result['applied'])));
        foreach ($result['applied'] as $version) {
            $output->writeln(sprintf('  - %s', $version));
        }

        $verification = $result['verification'];
        $output->writeln(sprintf(
            'Verify: OK fingerprint=%s batch=%s verified=%d remaining_pending=%d',
            $verification->fingerprint,
            $verification->batchNumber !== null ? (string) $verification->batchNumber : 'n/a',
            $verification->verifiedCount(),
            $verification->remainingPendingCount(),
        ));
    }

    private function renderPlanItem(MigrationPlanItem $item, Output $output): void
    {
        $output->writeln(sprintf(
            '  - [%s] %s %s (%s)',
            $item->isTransactional() ? 'tx' : 'non-tx',
            $item->version(),
            $item->description(),
            $item->migrationClass(),
        ));
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
