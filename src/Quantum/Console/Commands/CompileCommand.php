<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Compilation\CompilationResult;
use Quantum\Compilation\Contracts\ArtifactStoreInterface;
use Quantum\Compilation\Contracts\CompilerInterface;
use Quantum\Compilation\Contracts\CompiledControllerFactoryInterface;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\Metadata\ControllerMetadataResolverInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

final class CompileCommand extends Command
{
    public function name(): string
    {
        return 'compile';
    }

    public function description(): string
    {
        return 'Compila todos los controladores registrados en rutas en artefactos precompilados.';
    }

    public function usage(): string
    {
        return 'compile [--verbose] [--no-activate] [--retain=3]';
    }

    public function category(): string
    {
        return 'Cache';
    }

    public function aliases(): array
    {
        return ['controller:compile', 'controllers:compile'];
    }

    public function optionsHelp(): array
    {
        return [
            '--verbose' => 'Muestra cada controlador compilado y su artefacto generado.',
            '--no-activate' => 'Crea el build pero no lo activa (requiere activación manual posterior).',
            '--retain=N' => 'Retiene como máximo N builds anteriores (incluye build actual, default 3).',
            '--incremental' => 'Reutiliza artefactos no modificados del build actual en vez de recompilar todo.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $verbose = $input->hasOption('verbose');
        $noActivate = $input->hasOption('no-activate');
        $retainOption = $input->getOption('retain');
        $retain = is_numeric($retainOption) ? max(1, (int) $retainOption) : 3;
        $incremental = $input->hasOption('incremental');

        /** @var Router $router */
        $router = $app->make(Router::class);
        $compiler = $app->make(CompilerInterface::class);
        $store = $app->make(ArtifactStoreInterface::class);
        $metadata = $app->make(ControllerMetadataResolverInterface::class);

        $build = $store->createBuild();
        $output->writeln(sprintf('Build creado: %s', $build->id));
        $output->writeln(sprintf('Directorio build: %s', $store->buildsPath() . DIRECTORY_SEPARATOR . $build->id));
        $output->writeln('');

        $specs = $this->discoverControllers($app, $router);

        if ($specs === []) {
            $output->writeln('No se encontraron controladores para compilar (rutas vacías).');
            $output->writeln('Sugerencia: registra rutas en routes/web.php o usa RouteCache primero.');

            return 0;
        }

        $output->writeln(sprintf('Compilando %d controlador(es)...', count($specs)));
        $output->writeln('');

        $successCount = 0;
        $failCount = 0;
        $skippedIncremental = 0;
        $existingKeys = [];

        if ($incremental) {
            $currentBuild = $store->currentBuild();
            if ($currentBuild !== null) {
                $existingKeys = $this->discoverExistingArtifactKeys($store, $currentBuild->id);
            }
        }

        foreach ($compiler->compileBatch($specs, $metadata) as $result) {
            if (! $result->success) {
                $failCount++;
                $class = is_array($result->definition->action())
                    ? (is_object($result->definition->action()[0]) ? $result->definition->action()[0]::class : $result->definition->action()[0])
                    : (is_string($result->definition->action()) ? $result->definition->action() : 'unknown');

                $output->error(sprintf(
                    '[FAIL] %s: %s',
                    $class,
                    $result->error?->getMessage() ?? 'unknown compilation error',
                ));

                continue;
            }

            if ($incremental && isset($existingKeys[$result->artifactKey])) {
                $skippedIncremental++;
                if ($verbose) {
                    $output->writeln(sprintf(
                        '  [SKIP incremental] %s::%s',
                        $result->class,
                        $result->method,
                    ));
                }

                $existing = $existingKeys[$result->artifactKey];
                $existingBuildId = $existing['buildId'];
                $source = $store->buildsPath() . DIRECTORY_SEPARATOR . $existingBuildId . DIRECTORY_SEPARATOR . basename($existing['path']);
                $targetDir = $store->buildsPath() . DIRECTORY_SEPARATOR . $build->id;
                if (! is_dir($targetDir)) {
                    @mkdir($targetDir, 0777, true);
                }
                $target = $targetDir . DIRECTORY_SEPARATOR . basename($existing['path']);
                if (is_file($source)) {
                    @copy($source, $target);
                }
                $successCount++;

                continue;
            }

            try {
                $artifact = $store->write($result, $build->id);
                $successCount++;

                if ($verbose) {
                    $output->writeln(sprintf(
                        '  [OK] %s::%s -> %s',
                        $result->class,
                        $result->method,
                        basename($artifact->artifactPath),
                    ));
                }
            } catch (\Throwable $e) {
                $failCount++;
                $output->error(sprintf(
                    '[FAIL] %s::%s (write artifact): %s',
                    $result->class,
                    $result->method,
                    $e->getMessage(),
                ));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Resultado: %d OK / %d FAIL / %d SKIP',
            $successCount,
            $failCount,
            $skippedIncremental,
        ));

        if ($successCount === 0 && $failCount > 0) {
            $output->error('No se pudo compilar ningún controlador. Build conservado pero no activado.');

            return 1;
        }

        if ($noActivate) {
            $output->writeln(sprintf(
                'Build completado pero NO activado (--no-activate). Para activar manualmente: %s',
                $build->id,
            ));
            $store->pruneStaleBuilds($retain);

            return 0;
        }

        $activated = $store->activateBuild($build->id);
        $output->writeln(sprintf(
            'Build activado: %s (%d controladores)',
            $activated->id,
            $successCount,
        ));

        $workerCache = $app->make(CompiledControllerFactoryInterface::class);
        if ($workerCache instanceof \Quantum\Compilation\CompiledControllerFactory) {
            $cleared = $workerCache->workerCacheClear();
            if ($verbose) {
                $output->writeln(sprintf('Cache de worker invalidado: %d entradas liberadas.', $cleared));
            }
        }

        $pruned = $store->pruneStaleBuilds($retain);
        if ($verbose && $pruned > 0) {
            $output->writeln(sprintf('Builds obsoletos removidos: %d (retain=%d)', $pruned, $retain));
        }

        $output->writeln('');
        $output->writeln('¡Compilación de controladores completada con éxito!');

        return 0;
    }

    /**
     * @return array<int, array{class: class-string, method: string|null}>
     */
    private function discoverControllers(Application $app, Router $router): array
    {
        $routes = method_exists($router, 'routes') ? $router->routes() : [];
        $seen = [];
        $specs = [];

        foreach ($routes as $route) {
            $action = is_object($route) && method_exists($route, 'action') ? $route->action() : null;

            if ($action === null) {
                continue;
            }

            $spec = null;

            if (is_array($action) && count($action) === 2) {
                [$class, $method] = $action;
                $classString = is_object($class) ? $class::class : (string) $class;
                $key = $classString . '::' . $method;

                if (isset($seen[$key]) || ! class_exists($classString)) {
                    continue;
                }

                $seen[$key] = true;
                $spec = ['class' => $classString, 'method' => (string) $method];
            } elseif (is_string($action) && str_contains($action, '@')) {
                [$class, $method] = explode('@', $action, 2);
                $key = $class . '::' . $method;

                if (isset($seen[$key]) || ! class_exists($class)) {
                    continue;
                }

                $seen[$key] = true;
                $spec = ['class' => $class, 'method' => $method];
            } elseif (is_string($action) && class_exists($action)) {
                $key = $action . '::__invoke';

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $spec = ['class' => $action, 'method' => null];
            }

            if ($spec !== null) {
                $specs[] = $spec;
            }
        }

        return $specs;
    }

    /**
     * @return array<string, array{buildId: string, path: string}>
     */
    private function discoverExistingArtifactKeys(ArtifactStoreInterface $store, string $buildId): array
    {
        $dir = $store->buildsPath() . DIRECTORY_SEPARATOR . $buildId;

        if (! is_dir($dir)) {
            return [];
        }

        $keys = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            $keys[$base] = ['buildId' => $buildId, 'path' => $file];
        }

        return $keys;
    }
}
