<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Factory\FactoryDiscovery;
use Quantum\Database\Factory\FactoryManager;
use VoltStack\Framework\Application;

final class DbFactoryStatusCommand extends Command
{
    public function name(): string
    {
        return 'db:factory-status';
    }

    public function description(): string
    {
        return 'Lista las factories descubiertas por la configuración actual.';
    }

    public function usage(): string
    {
        return 'db:factory-status';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $app = $this->bootstrapApplication();
            $manager = new FactoryManager(
                app: $app,
                discovery: new FactoryDiscovery(
                    basePath: $app->basePath(),
                    paths: $this->resolvePaths($app),
                    classes: $this->resolveClasses($app),
                ),
                defaultSeed: $this->resolveDefaultSeed($app),
            );

            $rows = $manager->status();
            if ($rows === []) {
                $output->writeln('No se encontraron factories.');
                return 0;
            }

            foreach ($rows as $row) {
                $output->writeln(sprintf(
                    '%s :: %s -> %s (%s)',
                    $row['name'],
                    $row['class'],
                    $row['entity'],
                    $row['description'],
                ));
            }

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:factory-status failed: %s', $e->getMessage()));
            return 1;
        }
    }

    /**
     * @return list<string>
     */
    private function resolvePaths(Application $app): array
    {
        $paths = $app->config('database.factories.paths', ['database/factories']);
        return is_array($paths) ? array_values($paths) : ['database/factories'];
    }

    /**
     * @return list<class-string>
     */
    private function resolveClasses(Application $app): array
    {
        $classes = $app->config('database.factories.classes', []);
        return is_array($classes) ? array_values($classes) : [];
    }

    private function resolveDefaultSeed(Application $app): int
    {
        return (int) $app->config('database.factories.default_seed', 12345);
    }
}
