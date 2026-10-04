<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Contracts\SeederInterface;
use Quantum\Database\Seeders\SeederRunner;

/**
 * CLI entrypoint for the seeder subsystem.
 *
 * Wraps `SeederRunner` in a properly scoped request lifecycle so scoped
 * services (EntityManager, IdentityMap, UnitOfWork, TransactionManager,
 * connections) are freshly created and correctly cleaned up afterwards.
 *
 * Default behaviour (no options):
 *   - discover seeders from `database/seeders/*`,
 *   - prefer a conventional `DatabaseSeeder.php`,
 *   - execute it inside a database transaction,
 *   - flush the EntityManager and commit on success.
 */
final class DatabaseSeedCommand extends Command
{
    public function name(): string
    {
        return 'database:seed';
    }

    public function description(): string
    {
        return 'Ejecuta seeders para poblar la base de datos usando factories o consultas raw.';
    }

    public function usage(): string
    {
        return 'database:seed [--class=FQCN] [--path=directory]';
    }

    public function category(): string
    {
        return 'Database';
    }

    /**
     * @return array<string, string>
     */
    public function optionsHelp(): array
    {
        return [
            '--class=' => 'Clase FQCN del seeder a ejecutar (evita discovery convencional).',
            '--path='  => 'Directorio o archivo concreto para discovery de seeders (por defecto database/seeders).',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $executed = $this->runInCommandRuntime(function ($app) use ($input) {
            /** @var SeederRunner $runner */
            $runner = $app->make(SeederRunner::class);

            $classOption = $input->option('class');
            $pathOption  = $input->option('path');

            $class = is_string($classOption) && trim($classOption) !== ''
                ? trim($classOption)
                : null;

            $path = is_string($pathOption) && trim($pathOption) !== ''
                ? trim($pathOption)
                : null;

            return $runner->run($class, $path);
        });

        $output->writeln('Database seed');
        $output->writeln(sprintf(
            '  Seeder ejecutado: [%s]',
            $executed instanceof SeederInterface ? $executed::class : get_debug_type($executed),
        ));
        $output->writeln('  Resultado: OK');

        return 0;
    }
}
