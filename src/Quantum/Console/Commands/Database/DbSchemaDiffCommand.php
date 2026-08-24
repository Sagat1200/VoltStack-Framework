<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Container\Exceptions\BindingResolutionException;
use Quantum\Database\Schema\OrmSchemaProjector;
use Quantum\Database\Schema\SchemaComparator;
use Quantum\Database\Schema\SchemaManager;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;
use Quantum\Database\Orm\Support\EntityDiscovery;

final class DbSchemaDiffCommand extends Command
{
    public function name(): string
    {
        return 'db:schema-diff';
    }

    public function description(): string
    {
        return 'Compara el schema real con la metadata ORM y muestra el plan de cambios.';
    }

    public function usage(): string
    {
        return 'db:schema-diff [--json] [--sql] [--fail-on-data-loss]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--json' => 'Imprime el diff completo en JSON.',
            '--sql' => 'Muestra solo las sentencias SQL generables.',
            '--fail-on-data-loss' => 'Devuelve exit code 2 si el diff contiene acciones con riesgo de perdida de datos.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $report = $this->buildReport();
            $riskSummary = $report->riskSummary();
            $riskExitCode = $this->riskExitCode($input, $riskSummary, $output);

            if ($input->hasOption('json')) {
                $output->writeln((string) json_encode(
                    $report->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ));
                return $riskExitCode;
            }

            if ($input->hasOption('sql')) {
                $sql = $report->sqlStatements();
                if ($sql === []) {
                    $output->writeln('No hay SQL generable para el diff actual.');
                    return $riskExitCode;
                }

                foreach ($sql as $statement) {
                    $output->writeln($statement . ';');
                }

                return $riskExitCode;
            }

            if ($report->isEmpty()) {
                $output->writeln('No se detectaron diferencias entre schema real y metadata ORM.');
                return $riskExitCode;
            }

            if ($riskSummary['data_loss'] !== []) {
                $output->writeln('WARNING data-loss actions: ' . implode(', ', $riskSummary['data_loss']));
            }
            if ($riskSummary['operational'] !== []) {
                $output->writeln('WARNING operationally sensitive actions: ' . implode(', ', $riskSummary['operational']));
            }

            foreach ($report->actions as $action) {
                $riskMarker = $action->riskLevel !== 'none'
                    ? sprintf('[RISK:%s]', strtoupper($action->riskLevel))
                    : '';
                $line = sprintf('[%s]%s %s', strtoupper($action->kind), $riskMarker, $action->message);
                if ($action->sqlBatch !== []) {
                    $line .= ' SQL=' . implode(' | ', $action->sqlBatch);
                } elseif ($action->sql !== null) {
                    $line .= ' SQL=' . $action->sql;
                }

                $output->writeln($line);
            }

            return $riskExitCode;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:schema-diff failed: %s', $e->getMessage()));
            return 1;
        }
    }

    /**
     * @param array{data_loss:list<string>,operational:list<string>} $riskSummary
     */
    private function riskExitCode(Input $input, array $riskSummary, Output $output): int
    {
        if (!$input->hasOption('fail-on-data-loss')) {
            return 0;
        }

        if ($riskSummary['data_loss'] === []) {
            return 0;
        }

        $output->error('db:schema-diff blocked: data-loss actions detected (' . implode(', ', $riskSummary['data_loss']) . ').');

        return 2;
    }

    private function buildReport(): \Quantum\Database\Schema\SchemaDiffReport
    {
        $app = $this->bootstrapApplication();

        try {
            /** @var SchemaManager $schema */
            $schema = $app->make(SchemaManager::class);
            /** @var MetadataManagerInterface $metadata */
            $metadata = $app->make(MetadataManagerInterface::class);
            /** @var EntityDiscovery $discovery */
            $discovery = $app->make(EntityDiscovery::class);
        } catch (BindingResolutionException $e) {
            throw new \RuntimeException(
                'db:schema-diff requiere OrmServiceProvider y metadata ORM configurada.',
                previous: $e,
            );
        }

        $projector = new OrmSchemaProjector(
            metadata: $metadata,
            discovery: $discovery,
            driverName: $schema->driverName(),
        );

        $ignoredTables = [
            (string) $app->config('database.migrations.table', 'framework_migrations'),
        ];

        return (new SchemaComparator())->compare(
            actual: $schema->snapshot()->withoutTables($ignoredTables),
            desired: $projector->project(),
        );
    }
}