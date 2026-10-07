<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Routing\RouteArtifactManager;
use Quantum\Routing\PipelineOptimizationReport;
use Quantum\Routing\Router;

final class RouteCacheCommand extends Command
{
    public function name(): string
    {
        return 'route:cache';
    }

    public function description(): string
    {
        return 'Compila y guarda los artifacts AOT del sistema de rutas.';
    }

    public function usage(): string
    {
        return 'route:cache [--verbose] [--optimizer-only]';
    }

    public function category(): string
    {
        return 'Routing';
    }

    public function aliases(): array
    {
        return ['routes:cache'];
    }

    public function optionsHelp(): array
    {
        return [
            '--verbose' => 'Muestra cada artifact generado y su path final.',
            '--optimizer-only' => 'Imprime el reporte del pipeline optimizer sin escribir artifacts.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $verbose = $input->hasOption('verbose');
        $optimizerOnly = $input->hasOption('optimizer-only');

        return $this->runInCommandRuntime(function ($app) use ($optimizerOnly, $output, $verbose): int {
            $router = $app->make(Router::class);
            $manager = $app->make(RouteArtifactManager::class);
            $report = $manager->pipelineOptimizationReport($router);

            if ($optimizerOnly) {
                $this->renderOptimizerReport($output, $report, true);
                return 0;
            }

            $paths = $manager->compileAndWrite($router);

            if ($verbose) {
                foreach ($paths as $name => $path) {
                    $output->writeln(sprintf('  [%s] %s', $name, $path));
                }
            }

            $output->writeln('Artifacts de rutas compilados correctamente.');
            $output->writeln(sprintf('  Artifacts escritos: %d', count($paths)));

            if ($verbose) {
                $output->writeln('  Pipeline optimizer:');
                $output->writeln(sprintf('    Rutas analizadas: %d', $report->totalRoutes()));
                $output->writeln(sprintf('    Pipelines unicos: %d', $report->uniquePipelines()));
                $output->writeln(sprintf('    Rutas reutilizando pipeline: %d', $report->sharedRouteCount()));
                $output->writeln(sprintf('    Pipelines singleton: %d', $report->singletonPipelines()));
                $output->writeln(sprintf('    Max reutilizacion: %d rutas por pipeline', $report->maxPipelineReuse()));
                $output->writeln(sprintf(
                    '    Pipeline mas largo: %s (%d middleware)',
                    $report->longestRouteUri() ?? '-',
                    $report->longestPipelineLength(),
                ));

                $output->writeln(sprintf('    Top pipelines reutilizados: %d', count($report->topReusedPipelines())));

                foreach ($report->topReusedPipelines() as $pipeline) {
                    $output->writeln(sprintf(
                        '      - %d rutas -> %s (id:%s)',
                        $pipeline['routes'] ?? 0,
                        $pipeline['example'] ?? '-',
                        substr((string) ($pipeline['id'] ?? ''), 0, 12),
                    ));
                }

                $output->writeln(sprintf('    Ejemplos singleton: %d', count($report->singletonRouteExamples())));

                foreach ($report->singletonRouteExamples() as $routeUri) {
                    $output->writeln(sprintf('      - %s', (string) $routeUri));
                }
            }

            if ($report->hasWarnings()) {
                $output->writeln('  Advertencias del pipeline optimizer:');

                foreach ($report->warnings() as $warning) {
                    $output->writeln(sprintf('    - %s', $warning));
                }
            }

            return 0;
        });
    }

    private function renderOptimizerReport(Output $output, PipelineOptimizationReport $report, bool $expanded): void
    {
        $output->writeln('Pipeline optimizer:');
        $output->writeln(sprintf('  Rutas analizadas: %d', $report->totalRoutes()));
        $output->writeln(sprintf('  Pipelines unicos: %d', $report->uniquePipelines()));
        $output->writeln(sprintf('  Rutas reutilizando pipeline: %d', $report->sharedRouteCount()));
        $output->writeln(sprintf('  Pipelines singleton: %d', $report->singletonPipelines()));
        $output->writeln(sprintf('  Max reutilizacion: %d rutas por pipeline', $report->maxPipelineReuse()));
        $output->writeln(sprintf(
            '  Pipeline mas largo: %s (%d middleware)',
            $report->longestRouteUri() ?? '-',
            $report->longestPipelineLength(),
        ));

        if ($expanded) {
            $output->writeln(sprintf('  Top pipelines reutilizados: %d', count($report->topReusedPipelines())));

            foreach ($report->topReusedPipelines() as $pipeline) {
                $output->writeln(sprintf(
                    '    - %d rutas -> %s (id:%s)',
                    $pipeline['routes'] ?? 0,
                    $pipeline['example'] ?? '-',
                    substr((string) ($pipeline['id'] ?? ''), 0, 12),
                ));
            }

            $output->writeln(sprintf('  Ejemplos singleton: %d', count($report->singletonRouteExamples())));

            foreach ($report->singletonRouteExamples() as $routeUri) {
                $output->writeln(sprintf('    - %s', (string) $routeUri));
            }
        }

        if ($report->hasWarnings()) {
            $output->writeln('Advertencias del pipeline optimizer:');

            foreach ($report->warnings() as $warning) {
                $output->writeln(sprintf('  - %s', $warning));
            }
        }
    }
}
