<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use FilesystemIterator;
use Quantum\Cache\CacheManager;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class CacheClearCommand extends Command
{
    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'Limpia la cache de datos y los artefactos compilados del framework.';
    }

    public function usage(): string
    {
        return 'cache:clear [--data-only] [--compiled-only] [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Cache';
    }

    public function aliases(): array
    {
        return ['cache:clean'];
    }

    public function optionsHelp(): array
    {
        return [
            '--data-only' => 'Limpia solo la cache de datos configurada en cache.stores.*.',
            '--compiled-only' => 'Limpia solo cache.compiled.views y cache.compiled.pages.',
            '--verbose' => 'Muestra el detalle por store y por directorio compilado.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de limpiar cache.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $dataOnly = $input->hasOption('data-only');
        $compiledOnly = $input->hasOption('compiled-only');
        $verbose = $input->hasOption('verbose');

        if ($dataOnly && $compiledOnly) {
            $output->error('No puedes combinar --data-only con --compiled-only.');

            return 1;
        }

        return $this->runInCommandRuntime(function ($app) use ($compiledOnly, $dataOnly, $output, $verbose): int {
            $manager = $app->make(CacheManager::class);
            $deletedDataFiles = 0;
            $deletedViewFiles = 0;
            $deletedPageFiles = 0;

            if (! $compiledOnly) {
                foreach ($this->fileStorePaths($app->config('cache.stores', [])) as $name => $path) {
                    $deletedFiles = $this->countFiles($path);
                    $deletedDataFiles += $deletedFiles;
                    $manager->store((string) $name)->flush();
                    $this->deleteDirectory($path);

                    if ($verbose) {
                        $output->writeln(sprintf('  [data] %s -> %s (%d archivos)', $name, $path, $deletedFiles));
                    }
                }
            }

            if (! $dataOnly) {
                $compiledViewsPath = (string) $app->config('cache.compiled.views', $app->cachePath('compiled/views'));
                $compiledPagesPath = (string) $app->config('cache.compiled.pages', $app->cachePath('compiled/pages'));

                $deletedViewFiles = $this->deleteDirectory($compiledViewsPath);
                $deletedPageFiles = $this->deleteDirectory($compiledPagesPath);

                if ($verbose) {
                    $output->writeln(sprintf('  [compiled.views] %s (%d archivos)', $compiledViewsPath, $deletedViewFiles));
                    $output->writeln(sprintf('  [compiled.pages] %s (%d archivos)', $compiledPagesPath, $deletedPageFiles));
                }
            }

            $output->writeln('Cache limpiada correctamente.');
            $output->writeln(sprintf('  Datos eliminados: %d', $deletedDataFiles));
            $output->writeln(sprintf('  Vistas compiladas eliminadas: %d', $deletedViewFiles));
            $output->writeln(sprintf('  Paginas compiladas eliminadas: %d', $deletedPageFiles));

            return 0;
        }, requirePublishedConfig: $input->hasOption('require-published-config'));
    }

    /**
     * @param mixed $stores
     * @return array<string, string>
     */
    private function fileStorePaths(mixed $stores): array
    {
        if (! is_array($stores)) {
            return [];
        }

        $paths = [];

        foreach ($stores as $name => $config) {
            if (! is_string($name) || ! is_array($config)) {
                continue;
            }

            if (($config['driver'] ?? 'file') !== 'file') {
                continue;
            }

            $path = $config['path'] ?? null;

            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $paths[$name] = $path;
        }

        return $paths;
    }

    private function countFiles(string $directory): int
    {
        if (! is_dir($directory)) {
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $count = 0;

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    private function deleteDirectory(string $directory): int
    {
        if (! is_dir($directory)) {
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        $deleted = 0;

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                if (! @rmdir($path) && is_dir($path)) {
                    throw new RuntimeException(sprintf('Unable to remove cache directory [%s].', $path));
                }

                continue;
            }

            if (! @unlink($path) && is_file($path)) {
                throw new RuntimeException(sprintf('Unable to remove cache file [%s].', $path));
            }

            $deleted++;
        }

        if (! @rmdir($directory) && is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to remove cache root directory [%s].', $directory));
        }

        return $deleted;
    }
}
