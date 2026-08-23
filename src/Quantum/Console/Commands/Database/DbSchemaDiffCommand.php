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
        return 'db:schema-diff [--json] [--sql]';
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
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $report = $this->buildReport();

            if ($input->hasOption('json')) {
                $output->writeln((string) json_encode(
                    $report->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ));
                return 0;
            }

            if ($input->hasOption('sql')) {
                $sql = $report->sqlStatements();
                if ($sql === []) {
                    $output->writeln('No hay SQL generable para el diff actual.');
                    return 0;
                }

                foreach ($sql as $statement) {
                    $output->writeln($statement . ';');
                }

                return 0;
            }

            if ($report->isEmpty()) {
                $output->writeln('No se detectaron diferencias entre schema real y metadata ORM.');
                return 0;
            }

            foreach ($report->actions as $action) {
                $line = sprintf('[%s] %s', strtoupper($action->kind), $action->message);
                if ($action->sqlBatch !== []) {
                    $line .= ' SQL=' . implode(' | ', $action->sqlBatch);
                } elseif ($action->sql !== null) {
                    $line .= ' SQL=' . $action->sql;
                }

                $output->writeln($line);
            }

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:schema-diff failed: %s', $e->getMessage()));
            return 1;
        }
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

        return (new SchemaComparator())->compare(
            actual: $schema->snapshot(),
            desired: $projector->project(),
        );
    }
}
