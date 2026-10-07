<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use FilesystemIterator;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\View\Cache\CompiledViewStore;
use Quantum\View\ViewFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ViewCacheCommand extends Command
{
    public function name(): string
    {
        return 'view:cache';
    }

    public function description(): string
    {
        return 'Precompila las vistas configuradas y las guarda en cache.';
    }

    public function usage(): string
    {
        return 'view:cache [--verbose]';
    }

    public function category(): string
    {
        return 'Cache';
    }

    public function aliases(): array
    {
        return ['views:cache'];
    }

    public function optionsHelp(): array
    {
        return [
            '--verbose' => 'Muestra cada vista fuente y su archivo compilado en cache.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $verbose = $input->hasOption('verbose');

        return $this->runInCommandRuntime(function ($app) use ($output, $verbose): int {
            $factory = $app->make(ViewFactory::class);
            $store = $app->make(CompiledViewStore::class);
            $views = $this->discoverViews($factory->paths());

            if ($views === []) {
                $output->writeln('No se encontraron vistas para compilar.');

                return 0;
            }

            $compiled = 0;

            foreach ($views as $viewPath) {
                $compiledPath = $store->ensureCompiled($viewPath);
                $compiled++;

                if ($verbose) {
                    $output->writeln(sprintf('  [%d] %s', $compiled, $viewPath));
                    $output->writeln(sprintf('      -> %s', $compiledPath));
                }
            }

            $output->writeln('Vistas compiladas correctamente.');
            $output->writeln(sprintf('  Directorio cache: %s', $store->directory()));
            $output->writeln(sprintf('  Vistas compiladas: %d', $compiled));

            return 0;
        });
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    private function discoverViews(array $paths): array
    {
        $views = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $pathname = $file->getPathname();

                if (! $this->isViewFile($pathname)) {
                    continue;
                }

                $views[] = $pathname;
            }
        }

        sort($views);

        return array_values(array_unique($views));
    }

    private function isViewFile(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_ends_with($normalized, '.php') || str_ends_with($normalized, '.volt.php');
    }
}
