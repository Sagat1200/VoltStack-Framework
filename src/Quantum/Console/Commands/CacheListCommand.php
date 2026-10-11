<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Cache\CacheManager;
use Quantum\Cache\CachePoolSummary;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class CacheListCommand extends Command
{
    public function name(): string
    {
        return 'cache:list';
    }

    public function description(): string
    {
        return 'Lista los pools de cache configurados con su store, driver, prefijo y topologia.';
    }

    public function usage(): string
    {
        return 'cache:list [--json] [--verbose]';
    }

    public function category(): string
    {
        return 'Cache';
    }

    public function aliases(): array
    {
        return ['cache:pools'];
    }

    public function optionsHelp(): array
    {
        return [
            '--json' => 'Emite la lista de pools en formato JSON.',
            '--verbose' => 'Muestra el detalle completo de cada pool (topologia, clasificacion, TTL por defecto).',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $asJson = $input->hasOption('json');
        $verbose = $input->hasOption('verbose');

        return $this->runInCommandRuntime(function ($app) use ($asJson, $output, $verbose): int {
            $manager = $app->make(CacheManager::class);
            $pools = $manager->listPools();

            if ($asJson) {
                $output->write(json_encode([
                    'pools' => array_map(
                        static fn (CachePoolSummary $pool): array => $pool->toArray(),
                        $pools,
                    ),
                    'count' => count($pools),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            if ($pools === []) {
                $output->writeln('No hay pools de cache configurados.');

                return 0;
            }

            $output->writeln(sprintf('Pools de cache configurados: %d', count($pools)));
            $output->writeln('');

            foreach ($pools as $pool) {
                $output->writeln(sprintf('  <info>%s</info>', $pool->pool));
                $output->writeln(sprintf('    store:        %s', $pool->store));
                $output->writeln(sprintf('    driver:       %s', $pool->driver));
                $output->writeln(sprintf('    prefix:       %s', $pool->prefix));

                if ($verbose) {
                    $output->writeln(sprintf('    default_ttl:  %s', var_export($pool->defaultTtl, true)));
                    $output->writeln(sprintf('    topology:     %s', $pool->topology));
                    $output->writeln(sprintf('    classifica.:  %s', $pool->classification));
                }
            }

            return 0;
        });
    }
}
