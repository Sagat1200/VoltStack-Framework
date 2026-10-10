<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Closure;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Routing\Route;
use Quantum\Routing\Router;
use VoltStack\Runtime\Component\Component;

final class RouteListCommand extends Command
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'Muestra las rutas registradas por la aplicacion.';
    }

    public function usage(): string
    {
        return 'route:list [--require-published-config]';
    }

    public function category(): string
    {
        return 'Routing';
    }

    public function aliases(): array
    {
        return ['routes'];
    }

    public function optionsHelp(): array
    {
        return [
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de listar rutas.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(function ($app) use ($output): int {
            $router = $app->make(Router::class);
            $routes = $router->routes();

            if ($routes === []) {
                $output->writeln('No hay rutas registradas.');

                return 0;
            }

            $output->writeln('VoltStack Routes');
            $output->writeln();
            $output->writeln(sprintf('%-18s %-24s %s', 'Method', 'URI', 'Action'));
            $output->writeln(str_repeat('-', 78));

            foreach ($routes as $route) {
                $output->writeln(sprintf(
                    '%-18s %-24s %s',
                    implode('|', $route->methods()),
                    $route->uri(),
                    $this->formatAction($route),
                ));
            }

            $output->writeln();
            $output->writeln(sprintf('Total routes: %d', count($routes)));

            return 0;
        }, requirePublishedConfig: $input->hasOption('require-published-config'));
    }

    private function formatAction(Route $route): string
    {
        $action = $route->action();

        if ($action instanceof Closure) {
            return 'Closure';
        }

        if (is_array($action) && count($action) === 2) {
            $class = is_object($action[0]) ? $action[0]::class : (string) $action[0];

            return sprintf('%s@%s', $class, (string) $action[1]);
        }

        if (is_string($action)) {
            if (class_exists($action) && is_subclass_of($action, Component::class)) {
                return sprintf('%s [component]', $action);
            }

            if (class_exists($action)) {
                return sprintf('%s@__invoke', $action);
            }

            return $action;
        }

        if (is_object($action)) {
            return $action::class;
        }

        return get_debug_type($action);
    }
}
