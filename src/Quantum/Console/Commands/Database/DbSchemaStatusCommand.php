<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Schema\SchemaManager;
use VoltStack\Framework\Application;

final class DbSchemaStatusCommand extends Command
{
    public function name(): string
    {
        return 'db:schema-status';
    }

    public function description(): string
    {
        return 'Lista las tablas del schema real y resume sus columnas.';
    }

    public function usage(): string
    {
        return 'db:schema-status [--json]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--json' => 'Imprime el snapshot completo del schema en JSON.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $app = $this->bootstrapApplication();
            /** @var SchemaManager $schema */
            $schema = $app->make(SchemaManager::class);

            if ($input->hasOption('json')) {
                $output->writeln((string) json_encode(
                    $schema->snapshot()->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ));
                return 0;
            }

            $tables = $schema->snapshot()->tables;
            if ($tables === []) {
                $output->writeln(sprintf('Schema vacío para driver [%s].', $schema->driverName()));
                return 0;
            }

            $output->writeln(sprintf('Driver: %s', $schema->driverName()));
            foreach ($tables as $table) {
                $output->writeln(sprintf(
                    '- %s (columns=%d, primary_key=%s)',
                    $table->name,
                    count($table->columns),
                    $table->primaryKey === [] ? '-' : implode(',', $table->primaryKey),
                ));
            }

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:schema-status failed: %s', $e->getMessage()));
            return 1;
        }
    }
}
