<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use RuntimeException;
use VoltStack\Framework\Application;

final class MakeComponentCommand extends Command
{
    public function name(): string
    {
        return 'make:component';
    }

    public function description(): string
    {
        return 'Crea un componente reactivo nuevo a partir del stub del framework.';
    }

    public function usage(): string
    {
        return 'make:component <name> [--require-published-config]';
    }

    public function category(): string
    {
        return 'Generators';
    }

    public function argumentsHelp(): array
    {
        return [
            'name' => 'Nombre del componente, por ejemplo `Admin/UserCard`.',
        ];
    }

    public function optionsHelp(): array
    {
        return [
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de resolver los directorios de componentes.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $name = $input->arguments()[0] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $output->error('Debes indicar el nombre del componente. Ejemplo: php volt make:component Admin/UserCard');

            return 1;
        }

        $descriptor = $this->buildDescriptor($name, $input->hasOption('require-published-config'));
        $classPath = $descriptor['class_path'];
        $viewPath = $descriptor['view_path'];

        if (is_file($classPath)) {
            $output->error(sprintf('La clase del componente ya existe en [%s].', $classPath));

            return 1;
        }

        if (is_file($viewPath)) {
            $output->error(sprintf('La vista del componente ya existe en [%s].', $viewPath));

            return 1;
        }

        if (! is_dir($descriptor['class_directory']) && ! mkdir($descriptor['class_directory'], 0777, true) && ! is_dir($descriptor['class_directory'])) {
            throw new RuntimeException(sprintf('No se pudo crear el directorio [%s].', $descriptor['class_directory']));
        }

        if (! is_dir($descriptor['view_directory']) && ! mkdir($descriptor['view_directory'], 0777, true) && ! is_dir($descriptor['view_directory'])) {
            throw new RuntimeException(sprintf('No se pudo crear el directorio [%s].', $descriptor['view_directory']));
        }

        $classContents = strtr($this->classStub(), [
            '{{ namespace }}' => $descriptor['namespace'],
            '{{ class }}' => $descriptor['class'],
            '{{ title }}' => $descriptor['title'],
            '{{ view }}' => $descriptor['view_name'],
        ]);
        $viewContents = $this->viewStub();

        file_put_contents($classPath, $classContents);
        file_put_contents($viewPath, $viewContents);

        $output->writeln('Componente creado correctamente.');
        $output->writeln(sprintf('  Clase: %s\\%s', $descriptor['namespace'], $descriptor['class']));
        $output->writeln(sprintf('  Archivo clase: %s', $classPath));
        $output->writeln(sprintf('  Archivo vista: %s', $viewPath));

        return 0;
    }

    /**
     * @return array{
     *   class: string,
     *   namespace: string,
     *   class_directory: string,
     *   class_path: string,
     *   view_directory: string,
     *   view_path: string,
     *   view_name: string,
     *   title: string
     * }
     */
    private function buildDescriptor(string $name, bool $requirePublishedConfig = false): array
    {
        $normalized = trim(str_replace('/', '\\', $name), '\\ ');
        $segments = array_values(array_filter(explode('\\', $normalized)));

        if ($segments === []) {
            throw new RuntimeException('El nombre del componente no es valido.');
        }

        $class = array_pop($segments);
        $class = preg_replace('/\.php$/i', '', (string) $class) ?? (string) $class;

        [$classDirectory, $viewDirectory] = $this->componentDirectories($requirePublishedConfig);
        $namespace = $this->namespaceFromDirectory($classDirectory, 'App\\View\\Components');
        $classTargetDirectory = $classDirectory;
        $viewTargetDirectory = $viewDirectory;

        if ($segments !== []) {
            $namespace .= '\\' . implode('\\', $segments);
            $classTargetDirectory .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
            $viewTargetDirectory .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        }

        $viewFile = $this->viewFileName($class);
        $viewName = $this->viewName($segments, $class);

        return [
            'class' => $class,
            'namespace' => $namespace,
            'class_directory' => $classTargetDirectory,
            'class_path' => $classTargetDirectory . DIRECTORY_SEPARATOR . $class . '.php',
            'view_directory' => $viewTargetDirectory,
            'view_path' => $viewTargetDirectory . DIRECTORY_SEPARATOR . $viewFile . '.volt.php',
            'view_name' => $viewName,
            'title' => $this->title($segments, $class),
        ];
    }

    private function classStub(): string
    {
        $stubPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'Component' . DIRECTORY_SEPARATOR . 'Component.stub';

        if (! is_file($stubPath)) {
            throw new RuntimeException(sprintf('El stub del componente no existe en [%s].', $stubPath));
        }

        $contents = file_get_contents($stubPath);

        if ($contents === false) {
            throw new RuntimeException(sprintf('No se pudo leer el stub [%s].', $stubPath));
        }

        return $contents;
    }

    private function viewStub(): string
    {
        $stubPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'Component' . DIRECTORY_SEPARATOR . 'View.stub';

        if (! is_file($stubPath)) {
            throw new RuntimeException(sprintf('El stub de la vista del componente no existe en [%s].', $stubPath));
        }

        $contents = file_get_contents($stubPath);

        if ($contents === false) {
            throw new RuntimeException(sprintf('No se pudo leer el stub [%s].', $stubPath));
        }

        return $contents;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function componentDirectories(bool $requirePublishedConfig = false): array
    {
        $bootstrapPath = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (! is_file($bootstrapPath)) {
            return [
                $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'View' . DIRECTORY_SEPARATOR . 'Components',
                $this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views',
            ];
        }

        $app = $requirePublishedConfig
            ? $this->bootstrapApplication(true)
            : require $bootstrapPath;

        if (! $app instanceof Application) {
            return [
                $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'View' . DIRECTORY_SEPARATOR . 'Components',
                $this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views',
            ];
        }

        $configured = $app->config('ui-reactive.class_view_components', []);

        if (! is_array($configured) || count($configured) < 2) {
            return [
                $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'View' . DIRECTORY_SEPARATOR . 'Components',
                $this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views',
            ];
        }

        return [
            $this->normalizeDirectory((string) $configured[0]),
            $this->normalizeDirectory((string) $configured[1]),
        ];
    }

    private function namespaceFromDirectory(string $directory, string $fallback): string
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

        return $fallback;
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
        $parts = [...$segments, $class];
        $labels = array_map(
            static fn(string $part): string => trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $part)),
            $parts,
        );

        return implode(' ', $labels);
    }

    /**
     * @param array<int, string> $segments
     */
    private function viewName(array $segments, string $class): string
    {
        $parts = array_map(
            static fn(string $segment): string => strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $segment) ?? $segment),
            $segments,
        );
        $parts[] = $this->viewFileName($class);

        return implode('.', $parts);
    }

    private function viewFileName(string $class): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class) ?? $class);
    }
}
