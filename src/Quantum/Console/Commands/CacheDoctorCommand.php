<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Cache\CacheManager;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class CacheDoctorCommand extends Command
{
    public function name(): string
    {
        return 'cache:doctor';
    }

    public function description(): string
    {
        return 'Diagnostica un pool de cache: configuracion, diagnosticos del repositorio y advertencias.';
    }

    public function usage(): string
    {
        return 'cache:doctor [--pool=name] [--json] [--verbose]';
    }

    public function category(): string
    {
        return 'Cache';
    }

    public function aliases(): array
    {
        return ['cache:diagnose'];
    }

    public function optionsHelp(): array
    {
        return [
            '--pool=' => 'Nombre del pool a diagnosticar (por defecto: el pool por defecto configurado).',
            '--json' => 'Emite el diagnostico completo en formato JSON.',
            '--verbose' => 'Muestra el diagnostico completo del repositorio (context_fingerprint, storage_namespace, targets de invalidacion).',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $poolOption = $input->option('pool');
        $pool = is_string($poolOption) && $poolOption !== '' ? $poolOption : null;
        $asJson = $input->hasOption('json');
        $verbose = $input->hasOption('verbose');

        return $this->runInCommandRuntime(function ($app) use ($asJson, $output, $pool, $verbose): int {
            $manager = $app->make(CacheManager::class);

            try {
                $doctor = $manager->doctor($pool);
            } catch (\Throwable $e) {
                $output->error(sprintf('No se pudo diagnosticar el pool: %s', $e->getMessage()));

                return 1;
            }

            if ($asJson) {
                $output->write(json_encode($doctor->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            $output->writeln(sprintf('Diagnostico del pool: <info>%s</info>', $doctor->pool));
            $output->writeln(sprintf('  outcome:            %s', $doctor->outcome));
            $output->writeln(sprintf('  schema_version:     %s', $doctor->schemaVersion));
            $output->writeln(sprintf('  scope_fingerprint:  %s', $doctor->scopeFingerprint));
            $output->writeln(sprintf('  operation_id:       %s', $doctor->operationId));

            $output->writeln('');
            $output->writeln('Efectos aplicados:');
            foreach ($doctor->effects as $effect) {
                $output->writeln(sprintf('  - %s', $effect));
            }

            if ($doctor->warnings !== []) {
                $output->writeln('');
                $output->writeln('Advertencias:');
                foreach ($doctor->warnings as $warning) {
                    $output->writeln(sprintf('  ! %s', $warning));
                }
            }

            $output->writeln('');
            $output->writeln('Configuracion:');
            foreach ($doctor->configuration as $key => $value) {
                $output->writeln(sprintf('  %s: %s', $key, var_export($value, true)));
            }

            if ($verbose) {
                $output->writeln('');
                $output->writeln('Diagnosticos del repositorio:');
                foreach ($doctor->diagnostics as $key => $value) {
                    if (is_array($value)) {
                        $output->writeln(sprintf('  %s:', $key));
                        foreach ($value as $subKey => $subValue) {
                            $output->writeln(sprintf('    %s: %s', $subKey, var_export($subValue, true)));
                        }
                    } else {
                        $output->writeln(sprintf('  %s: %s', $key, var_export($value, true)));
                    }
                }
            }

            return 0;
        });
    }
}
