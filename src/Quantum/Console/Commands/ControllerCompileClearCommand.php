<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Compilation\Contracts\ArtifactStoreInterface;
use Quantum\Compilation\Contracts\CompiledControllerFactoryInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class ControllerCompileClearCommand extends Command
{
    public function name(): string
    {
        return 'controller-compiler:clear';
    }

    public function description(): string
    {
        return 'Elimina todos los builds de controladores precompilados y limpia el cache de worker.';
    }

    public function usage(): string
    {
        return 'controller-compiler:clear [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Cache';
    }

    public function aliases(): array
    {
        return ['compile:clear', 'controller:clear', 'controllers:clear', 'compile:clear-all'];
    }

    public function optionsHelp(): array
    {
        return [
            '--verbose' => 'Muestra detalles de los builds y entradas de cache eliminadas.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de limpiar builds de controladores.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $verbose = $input->hasOption('verbose');

        return $this->runInCommandRuntime(function ($app) use ($output, $verbose): int {
            $store = $app->make(ArtifactStoreInterface::class);

            if ($verbose) {
                $builds = $store->listBuilds();
                $output->writeln(sprintf('Builds existentes antes de limpiar: %d', count($builds)));
                foreach ($builds as $build) {
                    $status = $build->active ? '[ACTIVO]' : '';
                    $output->writeln(sprintf(
                        '  - %s (%d controllers) created=%s %s',
                        $build->id,
                        $build->controllerCount,
                        date('Y-m-d H:i:s', $build->createdAt),
                        $status,
                    ));
                }
            }

            $removedBuilds = $store->clearBuilds();
            $output->writeln(sprintf('Builds eliminados: %d', $removedBuilds));

            $factory = $app->make(CompiledControllerFactoryInterface::class);
            if ($factory instanceof \Quantum\Compilation\CompiledControllerFactory) {
                $clearedWorker = $factory->workerCacheClear();
                if ($verbose) {
                    $output->writeln(sprintf('Cache worker invalidado: %d entradas liberadas.', $clearedWorker));
                }
            }

            $output->writeln('');
            $output->writeln('Cache de compilación de controladores limpiado correctamente.');

            return 0;
        }, requirePublishedConfig: $input->hasOption('require-published-config'));
    }
}
