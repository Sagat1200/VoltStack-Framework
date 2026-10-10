<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use FilesystemIterator;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\View\Cache\CompiledViewStore;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ViewClearCommand extends Command
{
    public function name(): string
    {
        return 'view:clear';
    }

    public function description(): string
    {
        return 'Limpia la cache de vistas compiladas.';
    }

    public function usage(): string
    {
        return 'view:clear [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Cache';
    }

    public function aliases(): array
    {
        return ['views:clear'];
    }

    public function optionsHelp(): array
    {
        return [
            '--verbose' => 'Lista cada archivo compilado eliminado antes del resumen final.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de limpiar cache de vistas.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $verbose = $input->hasOption('verbose');

        return $this->runInCommandRuntime(function ($app) use ($output, $verbose): int {
            $store = $app->make(CompiledViewStore::class);
            $files = $verbose ? $this->compiledFiles($store->directory()) : [];
            $deleted = $store->clear();

            if ($deleted === 0) {
                $output->writeln('No habia vistas compiladas para eliminar.');

                return 0;
            }

            if ($verbose) {
                foreach ($files as $index => $file) {
                    $output->writeln(sprintf('  [%d] %s', $index + 1, $file));
                }
            }

            $output->writeln('Cache de vistas limpiada correctamente.');
            $output->writeln(sprintf('  Archivos eliminados: %d', $deleted));

            return 0;
        }, requirePublishedConfig: $input->hasOption('require-published-config'));
    }

    /**
     * @return array<int, string>
     */
    private function compiledFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $files[] = $item->getPathname();
        }

        sort($files);

        return $files;
    }
}
