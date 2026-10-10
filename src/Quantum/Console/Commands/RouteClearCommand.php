<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Routing\RouteArtifactManager;

final class RouteClearCommand extends Command
{
    public function name(): string
    {
        return 'route:clear';
    }

    public function description(): string
    {
        return 'Elimina los artifacts compilados del sistema de rutas.';
    }

    public function usage(): string
    {
        return 'route:clear [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Routing';
    }

    public function aliases(): array
    {
        return ['routes:clear'];
    }

    public function optionsHelp(): array
    {
        return [
            '--verbose' => 'Lista cada artifact eliminado antes del resumen final.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de eliminar artifacts de rutas.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $verbose = $input->hasOption('verbose');

        return $this->runInCommandRuntime(function ($app) use ($output, $verbose): int {
            $deleted = $app->make(RouteArtifactManager::class)->clear();

            if ($deleted === []) {
                $output->writeln('No habia artifacts de rutas para eliminar.');

                return 0;
            }

            if ($verbose) {
                foreach ($deleted as $name => $path) {
                    $output->writeln(sprintf('  [%s] %s', $name, $path));
                }
            }

            $output->writeln('Artifacts de rutas eliminados correctamente.');
            $output->writeln(sprintf('  Artifacts eliminados: %d', count($deleted)));

            return 0;
        }, requirePublishedConfig: $input->hasOption('require-published-config'));
    }
}
