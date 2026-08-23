<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Factory\FactoryManager;
use Quantum\Database\Seeder\SeederDiscovery;
use Quantum\Database\Seeder\SeederRunner;
use Quantum\Database\Support\ConnectionRegistry;
use VoltStack\Framework\Application;

final class DbSeedCommand extends Command
{
    public function name(): string
    {
        return 'db:seed';
    }

    public function description(): string
    {
        return 'Ejecuta seeders o fixtures configurados para la base de datos activa.';
    }

    public function usage(): string
    {
        return 'db:seed [seeders...] [--connection=primary] [--pretend] [--force]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function argumentsHelp(): array
    {
        return [
            'seeders...' => 'Nombres o clases de seeders a ejecutar. Si se omiten, se ejecutan todos.',
        ];
    }

    public function optionsHelp(): array
    {
        return [
            '--connection=' => 'Nombre de la conexión configurada a utilizar.',
            '--pretend' => 'Muestra qué seeders se ejecutarían sin aplicar cambios.',
            '--force' => 'Permite ejecutar seeders en producción si la política lo exige.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $runner = $this->makeRunner($app, $this->resolveConnectionName($input));

        try {
            $result = $runner->seed(
                targets: $input->arguments(),
                pretend: $input->hasOption('pretend'),
                force: $input->hasOption('force'),
            );

            $this->renderResult($result, $input->hasOption('pretend'), $output);
            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:seed failed: %s', $e->getMessage()));
            return 1;
        }
    }

    private function makeRunner(Application $app, ?string $connectionName): SeederRunner
    {
        /** @var ConnectionRegistry $registry */
        $registry = $app->make(ConnectionRegistry::class);
        $connection = $registry->connection($connectionName);
        $connection->connect();

        return new SeederRunner(
            app: $app,
            connection: $connection,
            discovery: new SeederDiscovery(
                basePath: $app->basePath(),
                paths: $this->resolvePaths($app),
                classes: $this->resolveClasses($app),
            ),
            factories: $app->make(FactoryManager::class),
        );
    }

    /**
     * @param array{planned:int,seeded:list<string>,pretended:list<string>} $result
     */
    private function renderResult(array $result, bool $pretend, Output $output): void
    {
        if ($result['planned'] === 0) {
            $output->writeln('No se encontraron seeders para ejecutar.');
            return;
        }

        if ($pretend) {
            $output->writeln(sprintf('Plan de seed: %d seeder(s).', $result['planned']));
            foreach ($result['pretended'] as $name) {
                $output->writeln(sprintf('  - %s', $name));
            }
            return;
        }

        $output->writeln(sprintf('Seeders ejecutados: %d', count($result['seeded'])));
        foreach ($result['seeded'] as $name) {
            $output->writeln(sprintf('  - %s', $name));
        }
    }

    private function resolveConnectionName(Input $input): ?string
    {
        return is_string($input->option('connection')) ? (string) $input->option('connection') : null;
    }

    /**
     * @return list<string>
     */
    private function resolvePaths(Application $app): array
    {
        $paths = $app->config('database.seeders.paths', ['database/seeders']);
        return is_array($paths) ? array_values($paths) : ['database/seeders'];
    }

    /**
     * @return list<class-string>
     */
    private function resolveClasses(Application $app): array
    {
        $classes = $app->config('database.seeders.classes', []);
        return is_array($classes) ? array_values($classes) : [];
    }
}
