<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Migration\MigrationDiscovery;
use Quantum\Database\Migration\MigrationExecutionException;
use Quantum\Database\Migration\MigrationLock;
use Quantum\Database\Migration\MigrationRecoveryPlan;
use Quantum\Database\Migration\MigrationRecoveryStore;
use Quantum\Database\Migration\MigrationRepository;
use Quantum\Database\Migration\MigrationRunner;
use Quantum\Database\Migration\MigrationVerificationResult;
use Quantum\Database\Support\ConnectionRegistry;
use VoltStack\Framework\Application;

final class DbMigrateRecoverCommand extends Command
{
    public function name(): string
    {
        return 'db:migrate-recover';
    }

    public function description(): string
    {
        return 'Reanuda, revierte parcialmente o reconcilia una ejecucion de migraciones fallida.';
    }

    public function usage(): string
    {
        return 'db:migrate-recover [--connection=primary] [--pretend] [--strategy=auto]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--connection=' => 'Nombre de la conexión configurada a utilizar.',
            '--pretend' => 'Muestra el recovery plan sin ejecutarlo.',
            '--strategy=' => 'Estrategia: auto, resume, rollback-partial, continue o reconcile.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $runner = $this->makeRunner($app, $this->resolveConnectionName($input));
        $pretend = $input->hasOption('pretend');
        $strategy = $this->resolveStrategy($input);

        try {
            $plan = $runner->planRecovery($strategy);
            if ($plan === null) {
                $output->writeln('No hay recovery plan pendiente.');
                return 0;
            }

            $this->renderPlan($plan, $output);

            if ($pretend) {
                $output->writeln('Dry-run activado: no se ejecutaron cambios.');
                return 0;
            }

            $result = $runner->recover(false, $strategy);
            $this->renderResult($plan, $result, $output);

            return 0;
        } catch (MigrationExecutionException $e) {
            $checkpoint = $e->checkpoint;
            $advice = $e->recoveryAdvice();

            $output->error(sprintf(
                'db:migrate-recover failed: failure=%s retryable=%s phase=%s fingerprint=%s batch=%s position=%d/%d completed=%d failed_version=%s failed_migration=%s message=%s',
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
            $output->error(sprintf('db:migrate-recover failed: %s', $e->getMessage()));
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
        $recovery = MigrationRecoveryStore::forConnection(
            root: $app->storagePath('framework/database/migrations/recovery'),
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
            $recovery,
        );
    }

    private function renderPlan(MigrationRecoveryPlan $plan, Output $output): void
    {
        $output->writeln(sprintf(
            'Recovery plan: action=%s source=%s phase=%s fingerprint=%s batch=%s items=%d',
            $plan->action,
            $plan->sourceOperation,
            $plan->checkpoint->phase,
            $plan->checkpoint->fingerprint,
            $plan->checkpoint->batchNumber !== null ? (string) $plan->checkpoint->batchNumber : 'n/a',
            $plan->plannedCount(),
        ));
        $output->writeln(sprintf('Summary: %s', $plan->summary));

        foreach ($plan->versions as $version) {
            $output->writeln(sprintf('  - %s', $version));
        }
    }

    /**
     * @param array{
     *   action:string,
     *   planned:int,
     *   fingerprint:string,
     *   applied:list<string>,
     *   rolled_back:list<string>,
     *   reconciled:list<string>,
     *   pretended:list<string>,
     *   verification:MigrationVerificationResult|null
     * } $result
     */
    private function renderResult(MigrationRecoveryPlan $plan, array $result, Output $output): void
    {
        if ($result['action'] === 'resume_migrate') {
            $output->writeln(sprintf('Recovery completado: migraciones aplicadas=%d', count($result['applied'])));
            foreach ($result['applied'] as $version) {
                $output->writeln(sprintf('  - %s', $version));
            }

            $verification = $result['verification'];
            if ($verification instanceof MigrationVerificationResult) {
                $output->writeln(sprintf(
                    'Verify: OK fingerprint=%s batch=%s verified=%d remaining_pending=%d',
                    $verification->fingerprint,
                    $verification->batchNumber !== null ? (string) $verification->batchNumber : 'n/a',
                    $verification->verifiedCount(),
                    $verification->remainingPendingCount(),
                ));
            }

            return;
        }

        if (in_array($result['action'], ['rollback_partial', 'continue_rollback'], true)) {
            $output->writeln(sprintf(
                'Recovery completado: action=%s rolled_back=%d',
                $result['action'],
                count($result['rolled_back']),
            ));
            foreach ($result['rolled_back'] as $version) {
                $output->writeln(sprintf('  - %s', $version));
            }

            return;
        }

        $output->writeln(sprintf(
            'Recovery completado: action=%s reconciled=%d',
            $plan->action,
            count($result['reconciled']),
        ));
        foreach ($result['reconciled'] as $version) {
            $output->writeln(sprintf('  - %s', $version));
        }
    }

    private function resolveConnectionName(Input $input): ?string
    {
        return is_string($input->option('connection')) ? (string) $input->option('connection') : null;
    }

    private function resolveStrategy(Input $input): ?string
    {
        return is_string($input->option('strategy')) ? (string) $input->option('strategy') : null;
    }
}