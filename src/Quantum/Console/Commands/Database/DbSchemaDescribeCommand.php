<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Schema\SchemaManager;

final class DbSchemaDescribeCommand extends Command
{
    public function name(): string
    {
        return 'db:schema-describe';
    }

    public function description(): string
    {
        return 'Describe columnas y metadatos básicos de una tabla del schema real.';
    }

    public function usage(): string
    {
        return 'db:schema-describe <table> [--json]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function argumentsHelp(): array
    {
        return [
            'table' => 'Nombre de la tabla a inspeccionar.',
        ];
    }

    public function optionsHelp(): array
    {
        return [
            '--json' => 'Imprime la descripción completa en JSON.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $table = trim($input->arguments()[0] ?? '');
        if ($table === '') {
            $output->error('Debes indicar una tabla.');
            return 1;
        }

        try {
            /** @var SchemaManager $schema */
            $schema = $this->bootstrapApplication()->make(SchemaManager::class);
            $details = $schema->describeTable($table);

            if ($input->hasOption('json')) {
                $output->writeln((string) json_encode(
                    $details->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ));
                return 0;
            }

            $output->writeln(sprintf('Table: %s', $details->name));
            $output->writeln(sprintf('Primary key: %s', $details->primaryKey === [] ? '-' : implode(',', $details->primaryKey)));

            foreach ($details->columns as $column) {
                $output->writeln(sprintf(
                    '- %s type=%s nullable=%s primary=%s auto_increment=%s default=%s',
                    $column->name,
                    $column->nativeType,
                    $column->nullable ? 'yes' : 'no',
                    $column->primaryKey ? 'yes' : 'no',
                    $column->autoIncrement ? 'yes' : 'no',
                    $column->defaultValue === null ? 'null' : (string) $column->defaultValue,
                ));
            }

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:schema-describe failed: %s', $e->getMessage()));
            return 1;
        }
    }
}
