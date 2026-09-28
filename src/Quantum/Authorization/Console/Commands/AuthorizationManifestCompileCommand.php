<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Routing\Route;
use Quantum\Routing\RouteCollection;
use Quantum\Routing\RouteMatch;

final class AuthorizationManifestCompileCommand extends Command
{
    public function name(): string
    {
        return 'authz:manifest:compile';
    }

    public function description(): string
    {
        return 'Compila el manifest de autorizacion para todas las rutas descubribles configuradas.';
    }

    public function usage(): string
    {
        return 'authz:manifest:compile [--verbose] [--dry-run]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:manifest:compile', 'authz:compile-manifest'];
    }

    public function optionsHelp(): array
    {
        return [
            '--verbose' => 'Muestra cada ruta compilada con su fingerprint y requirements.',
            '--dry-run' => 'Calcula el manifest pero no lo persiste fisicamente en el store.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $routes = $app->make(RouteCollection::class);
        $resolver = $app->make(AuthorizationMetadataResolverInterface::class);
        $store = $app->make(AuthorizationManifestStoreInterface::class);
        $verbose = $input->hasOption('verbose');
        $dryRun = $input->hasOption('dry-run');

        $allRoutes = $routes->all();

        if (count($allRoutes) === 0) {
            $output->writeln('No se encontraron rutas para compilar el manifest de autorizacion.');

            return 0;
        }

        $compiled = 0;
        $skipped = 0;

        foreach ($allRoutes as $route) {
            if (! $route instanceof Route) {
                $skipped++;
                continue;
            }

            $definition = $this->controllerDefinitionFromRoute($route);
            $methods = $route->methods();

            foreach ($methods as $method) {
                $match = new RouteMatch($route, $this->parameterDefaults($route), $method);

                try {
                    $metadata = $resolver->resolve($match, $definition);
                } catch (\Throwable $exception) {
                    $skipped++;
                    if ($verbose) {
                        $output->writeln(sprintf(
                            '  <error>[SKIP] %s %s -> %s: %s</error>',
                            $method,
                            $route->path(),
                            $this->formatActionForOutput($definition),
                            $exception->getMessage(),
                        ));
                    }

                    continue;
                }

                $fingerprint = $metadata->fingerprint();

                if ($fingerprint === null) {
                    $skipped++;
                    continue;
                }

                $compiled++;

                if ($verbose) {
                    $output->writeln(sprintf(
                        '  [%d] %s %s',
                        $compiled,
                        $method,
                        $route->path(),
                    ));
                    $output->writeln(sprintf(
                        '      fingerprint: %s',
                        $fingerprint,
                    ));
                    $output->writeln(sprintf(
                        '      public: %s | requirements: %d',
                        $metadata->public() ? 'yes' : 'no',
                        count($metadata->requirements()),
                    ));
                }
            }
        }

        if ($dryRun) {
            $output->writeln('[dry-run] Manifest de autorizacion calculado (no persistido).');
        } else {
            $output->writeln('Manifest de autorizacion compilado correctamente.');
        }

        $output->writeln(sprintf('  Rutas compiladas: %d', $compiled));
        if ($skipped > 0) {
            $output->writeln(sprintf('  Saltadas: %d', $skipped));
        }

        return 0;
    }

    private function controllerDefinitionFromRoute(Route $route): ?ControllerDefinition
    {
        $action = $route->definition()->action();

        if ($action === null || $action === '') {
            return null;
        }

        try {
            return new ControllerDefinition($action);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatActionForOutput(?ControllerDefinition $definition): string
    {
        if ($definition === null) {
            return 'inline';
        }

        $action = $definition->action();

        if (is_string($action)) {
            return $action;
        }

        if (is_array($action)) {
            $class = is_object($action[0] ?? null) ? get_class($action[0]) : (string) ($action[0] ?? '');
            $method = (string) ($action[1] ?? '');

            return trim($class . '::' . $method, ':');
        }

        return 'callable';
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterDefaults(Route $route): array
    {
        $path = $route->path();
        $parameters = [];

        if (preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_-]*)\??\}#', $path, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $parameters[$name] = null;
            }
        }

        ksort($parameters);

        return $parameters;
    }
}
