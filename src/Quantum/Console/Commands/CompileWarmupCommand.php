<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Compilation\CompilationResult;
use Quantum\Compilation\Contracts\ArtifactStoreInterface;
use Quantum\Compilation\Contracts\CompilerInterface;
use Quantum\Controllers\Metadata\ControllerMetadataResolverInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class CompileWarmupCommand extends Command
{
    public function name(): string
    {
        return 'compile:warmup';
    }

    public function description(): string
    {
        return 'Realiza warmup de rutas hot (configuradas en controller_compilation.warmup.hot_routes) para prevenir cold starts.';
    }

    public function usage(): string
    {
        return 'compile:warmup [--verbose] [--rebuild-current]';
    }

    public function category(): string
    {
        return 'Cache';
    }

    public function aliases(): array
    {
        return ['controller:warmup', 'controllers:warmup'];
    }

    public function optionsHelp(): array
    {
        return [
            '--verbose' => 'Muestra cada ruta hot compilada.',
            '--rebuild-current' => 'Fuerza la recompilación de las rutas hot sobre el build actual activo en vez de crear uno nuevo.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $verbose = $input->hasOption('verbose');
        $rebuildCurrent = $input->hasOption('rebuild-current');

        $store = $app->make(ArtifactStoreInterface::class);
        $compiler = $app->make(CompilerInterface::class);
        $metadata = $app->make(ControllerMetadataResolverInterface::class);

        $hotRoutes = $app->config('controller_compilation.warmup.hot_routes', []);
        if (! is_array($hotRoutes)) {
            $hotRoutes = [];
        }

        if ($hotRoutes === []) {
            $output->writeln('No hay rutas hot configuradas en controller_compilation.warmup.hot_routes.');
            $output->writeln('Sugerencia: agrega controladores críticos en formato "Class@method" o "InvokableClass" al config.');

            return 0;
        }

        $specs = [];
        foreach ($hotRoutes as $route) {
            if (! is_string($route) || trim($route) === '') {
                continue;
            }

            if (str_contains($route, '@')) {
                [$class, $method] = explode('@', $route, 2);
                if (! class_exists($class)) {
                    $output->error(sprintf('[SKIP] Hot route class not found: %s', $class));
                    continue;
                }
                $specs[] = ['class' => $class, 'method' => $method];
            } elseif (class_exists($route)) {
                $specs[] = ['class' => $route, 'method' => null];
            } else {
                $output->error(sprintf('[SKIP] Hot route class not found: %s', $route));
            }
        }

        if ($specs === []) {
            $output->writeln('No hay rutas hot válidas para compilar.');

            return 1;
        }

        $output->writeln(sprintf('Warmup de %d ruta(s) hot...', count($specs)));
        $output->writeln('');

        $buildId = null;

        if ($rebuildCurrent) {
            $currentBuild = $store->currentBuild();
            if ($currentBuild === null) {
                $output->error('No existe build actual activo. Usa `compile` primero u omite --rebuild-current.');

                return 1;
            }
            $buildId = $currentBuild->id;
            $output->writeln(sprintf('Recompilando sobre build actual: %s', $buildId));
        } else {
            $build = $store->createBuild();
            $buildId = $build->id;
            $output->writeln(sprintf('Nuevo build de warmup: %s', $buildId));
        }

        $success = 0;
        $fail = 0;

        foreach ($compiler->compileBatch($specs, $metadata) as $result) {
            if (! $result->success) {
                $fail++;
                $output->error(sprintf(
                    '[FAIL warmup] %s@%s: %s',
                    $result->class,
                    $result->method,
                    $result->error?->getMessage() ?? 'unknown',
                ));

                continue;
            }

            try {
                $artifact = $store->write($result, $buildId);
                $success++;

                if ($verbose) {
                    $output->writeln(sprintf(
                        '  [WARMUP OK] %s::%s -> %s',
                        $result->class,
                        $result->method,
                        basename($artifact->artifactPath),
                    ));
                }
            } catch (\Throwable $e) {
                $fail++;
                $output->error(sprintf(
                    '[FAIL warmup write] %s::%s: %s',
                    $result->class,
                    $result->method,
                    $e->getMessage(),
                ));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('Warmup: %d OK / %d FAIL', $success, $fail));

        if (! $rebuildCurrent) {
            $activated = $store->activateBuild($buildId);
            $output->writeln(sprintf('Build warmup activado: %s', $activated->id));
        }

        $output->writeln('Warmup completado. Cold-start evitado para las rutas hot configuradas.');

        return $fail === 0 ? 0 : 1;
    }
}
