<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Seeder\SeederDiscovery;
use VoltStack\Framework\Application;

final class DbSeedStatusCommand extends Command
{
    public function name(): string
    {
        return 'db:seed-status';
    }

    public function description(): string
    {
        return 'Lista los seeders y fixtures descubiertos por la configuración actual.';
    }

    public function usage(): string
    {
        return 'db:seed-status';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $app = $this->bootstrapApplication();
            $discovery = new SeederDiscovery(
                basePath: $app->basePath(),
                paths: $this->resolvePaths($app),
                classes: $this->resolveClasses($app),
            );

            $seeders = $discovery->discover();
            if ($seeders === []) {
                $output->writeln('No se encontraron seeders.');
                return 0;
            }

            foreach ($seeders as $seeder) {
                $output->writeln(sprintf(
                    '%s :: %s (%s)',
                    $seeder->name(),
                    $seeder::class,
                    $seeder->description(),
                ));
            }

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:seed-status failed: %s', $e->getMessage()));
            return 1;
        }
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
