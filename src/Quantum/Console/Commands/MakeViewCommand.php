<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use RuntimeException;
use VoltStack\Framework\Application;

final class MakeViewCommand extends Command
{
    public function name(): string
    {
        return 'make:view';
    }

    public function description(): string
    {
        return 'Crea una vista nueva a partir del stub del framework.';
    }

    public function usage(): string
    {
        return 'make:view <name> [--require-published-config]';
    }

    public function category(): string
    {
        return 'Generators';
    }

    public function argumentsHelp(): array
    {
        return [
            'name' => 'Nombre de la vista, por ejemplo `admin/profile_card` o `admin.profile_card`.',
        ];
    }

    public function optionsHelp(): array
    {
        return [
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de resolver el directorio de vistas.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $name = $input->arguments()[0] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $output->error('Debes indicar el nombre de la vista. Ejemplo: php volt make:view admin/profile_card');

            return 1;
        }

        $descriptor = $this->buildDescriptor($name, $input->hasOption('require-published-config'));
        $viewPath = $descriptor['view_path'];

        if (is_file($viewPath)) {
            $output->error(sprintf('La vista ya existe en [%s].', $viewPath));

            return 1;
        }

        if (! is_dir($descriptor['view_directory']) && ! mkdir($descriptor['view_directory'], 0777, true) && ! is_dir($descriptor['view_directory'])) {
            throw new RuntimeException(sprintf('No se pudo crear el directorio [%s].', $descriptor['view_directory']));
        }

        $contents = strtr($this->stub(), [
            '{{ title }}' => $descriptor['title'],
        ]);

        file_put_contents($viewPath, $contents);

        $output->writeln('Vista creada correctamente.');
        $output->writeln(sprintf('  Nombre: %s', $descriptor['view_name']));
        $output->writeln(sprintf('  Archivo: %s', $viewPath));

        return 0;
    }

    /**
     * @return array{view_directory: string, view_path: string, view_name: string, title: string}
     */
    private function buildDescriptor(string $name, bool $requirePublishedConfig = false): array
    {
        $normalized = trim(str_replace(['\\', '.'], '/', $name), '/ ');
        $segments = array_values(array_filter(explode('/', $normalized)));

        if ($segments === []) {
            throw new RuntimeException('El nombre de la vista no es valido.');
        }

        $viewDirectory = $this->viewDirectory($requirePublishedConfig);
        $targetDirectory = $viewDirectory;

        if (count($segments) > 1) {
            $targetDirectory .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($segments, 0, -1));
        }

        $viewFile = array_pop($segments);
        $viewFile = preg_replace('/(?:\.volt)?\.php$/i', '', (string) $viewFile) ?? (string) $viewFile;
        $viewFile = strtolower(str_replace('-', '_', $viewFile));

        $viewNameSegments = array_map(
            static fn(string $segment): string => strtolower(str_replace('-', '_', $segment)),
            [...$segments, $viewFile],
        );

        return [
            'view_directory' => $targetDirectory,
            'view_path' => $targetDirectory . DIRECTORY_SEPARATOR . $viewFile . '.volt.php',
            'view_name' => implode('.', $viewNameSegments),
            'title' => $this->title($viewFile),
        ];
    }

    private function stub(): string
    {
        $stubPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'View' . DIRECTORY_SEPARATOR . 'View.stub';

        if (! is_file($stubPath)) {
            throw new RuntimeException(sprintf('El stub de la vista no existe en [%s].', $stubPath));
        }

        $contents = file_get_contents($stubPath);

        if ($contents === false) {
            throw new RuntimeException(sprintf('No se pudo leer el stub [%s].', $stubPath));
        }

        return $contents;
    }

    private function viewDirectory(bool $requirePublishedConfig = false): string
    {
        $bootstrapPath = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (! is_file($bootstrapPath)) {
            return $this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
        }

        $app = $requirePublishedConfig
            ? $this->bootstrapApplication(true)
            : require $bootstrapPath;

        if (! $app instanceof Application) {
            return $this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
        }

        $configured = $app->config('ui-reactive.class_view_components', []);

        if (! is_array($configured) || count($configured) < 2) {
            return $this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
        }

        return $this->normalizeDirectory((string) $configured[1]);
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

    private function title(string $viewFile): string
    {
        $spaced = str_replace(['-', '_'], ' ', $viewFile);

        return ucwords($spaced);
    }
}
