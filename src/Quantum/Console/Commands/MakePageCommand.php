<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use RuntimeException;
use VoltStack\Framework\Application;

final class MakePageCommand extends Command
{
    public function name(): string
    {
        return 'make:page';
    }

    public function description(): string
    {
        return 'Crea una pagina reactiva nueva a partir del stub del framework.';
    }

    public function usage(): string
    {
        return 'make:page <name> [--require-published-config]';
    }

    public function category(): string
    {
        return 'Generators';
    }

    public function argumentsHelp(): array
    {
        return [
            'name' => 'Nombre de la pagina, por ejemplo `Admin/Dashboard`.',
        ];
    }

    public function optionsHelp(): array
    {
        return [
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de resolver el directorio de paginas.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $name = $input->arguments()[0] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $output->error('Debes indicar el nombre de la pagina. Ejemplo: php volt make:page Admin/Dashboard');

            return 1;
        }

        $descriptor = $this->buildDescriptor($name, $input->hasOption('require-published-config'));
        $targetPath = $descriptor['path'];

        if (is_file($targetPath)) {
            $output->error(sprintf('La pagina ya existe en [%s].', $targetPath));

            return 1;
        }

        if (! is_dir($descriptor['directory']) && ! mkdir($descriptor['directory'], 0777, true) && ! is_dir($descriptor['directory'])) {
            throw new RuntimeException(sprintf('No se pudo crear el directorio [%s].', $descriptor['directory']));
        }

        $contents = strtr($this->stub(), [
            '{{ namespace }}' => $descriptor['namespace'],
            '{{ class }}' => $descriptor['class'],
            '{{ title }}' => $descriptor['title'],
        ]);

        file_put_contents($targetPath, $contents);

        $output->writeln('Pagina creada correctamente.');
        $output->writeln(sprintf('  Clase: %s\\%s', $descriptor['namespace'], $descriptor['class']));
        $output->writeln(sprintf('  Archivo: %s', $targetPath));

        return 0;
    }

    /**
     * @return array{class: string, namespace: string, directory: string, path: string, title: string}
     */
    private function buildDescriptor(string $name, bool $requirePublishedConfig = false): array
    {
        $normalized = trim(str_replace('/', '\\', $name), '\\ ');
        $segments = array_values(array_filter(explode('\\', $normalized)));

        if ($segments === []) {
            throw new RuntimeException('El nombre de la pagina no es valido.');
        }

        $class = array_pop($segments);
        $class = preg_replace('/\.php$/i', '', (string) $class) ?? (string) $class;

        if (! str_ends_with($class, 'Page')) {
            $class .= 'Page';
        }

        $directory = $this->pageDirectory($requirePublishedConfig);
        $namespace = $this->namespaceFromDirectory($directory);

        if ($segments !== []) {
            $namespace .= '\\' . implode('\\', $segments);
            $directory .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        }

        return [
            'class' => $class,
            'namespace' => $namespace,
            'directory' => $directory,
            'path' => $directory . DIRECTORY_SEPARATOR . $class . '.php',
            'title' => $this->title($segments, $class),
        ];
    }

    private function stub(): string
    {
        $stubPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'Page' . DIRECTORY_SEPARATOR . 'Page.stub';

        if (! is_file($stubPath)) {
            throw new RuntimeException(sprintf('El stub de la pagina no existe en [%s].', $stubPath));
        }

        $contents = file_get_contents($stubPath);

        if ($contents === false) {
            throw new RuntimeException(sprintf('No se pudo leer el stub [%s].', $stubPath));
        }

        return $contents;
    }

    private function pageDirectory(bool $requirePublishedConfig = false): string
    {
        $bootstrapPath = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (! is_file($bootstrapPath)) {
            return $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Pages';
        }

        $app = $requirePublishedConfig
            ? $this->bootstrapApplication(true)
            : require $bootstrapPath;

        if (! $app instanceof Application) {
            return $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Pages';
        }

        $configured = $app->config('ui-reactive.single_page_components');

        if (is_array($configured) && $configured !== []) {
            foreach ($configured as $namespace => $directory) {
                if (! is_string($directory) || trim($directory) === '') {
                    continue;
                }

                if (is_string($namespace) && str_starts_with(trim($namespace, '\\ '), 'App')) {
                    return $this->normalizeDirectory($directory);
                }
            }

            foreach ($configured as $directory) {
                if (is_string($directory) && trim($directory) !== '') {
                    return $this->normalizeDirectory($directory);
                }
            }
        }

        if (! is_string($configured) || trim($configured) === '') {
            return $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Pages';
        }

        return $this->normalizeDirectory($configured);
    }

    private function namespaceFromDirectory(string $directory): string
    {
        $baseAppPath = $this->normalizeDirectory($this->basePath . DIRECTORY_SEPARATOR . 'app');

        if (str_starts_with($directory, $baseAppPath)) {
            $relative = trim(substr($directory, strlen($baseAppPath)), '\\/');
            $namespace = 'App';

            if ($relative !== '') {
                $namespace .= '\\' . str_replace(['/', '\\'], '\\', $relative);
            }

            return $namespace;
        }

        return 'App\\Pages';
    }

    private function normalizeDirectory(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        if ($this->isAbsolutePath($normalized)) {
            return rtrim($normalized, '\\/');
        }

        return rtrim($this->basePath . DIRECTORY_SEPARATOR . ltrim($normalized, '\\/'), '\\/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    /**
     * @param array<int, string> $segments
     */
    private function title(array $segments, string $class): string
    {
        $resource = str_ends_with($class, 'Page')
            ? substr($class, 0, -strlen('Page'))
            : $class;
        $resource = $resource === '' ? $class : $resource;

        $parts = [...$segments, $resource];
        $labels = array_map(
            static fn(string $part): string => trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $part)),
            $parts,
        );

        return implode(' ', $labels);
    }
}
