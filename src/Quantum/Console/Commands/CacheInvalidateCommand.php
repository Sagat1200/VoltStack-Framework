<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Cache\CacheManager;
use Quantum\Config\ConfigRepository;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class CacheInvalidateCommand extends Command
{
    public function name(): string
    {
        return 'cache:invalidate';
    }

    public function description(): string
    {
        return 'Invalida tags (o dependencias) de un pool de cache. Por defecto es dry-run; usa --apply para ejecutar.';
    }

    public function usage(): string
    {
        return 'cache:invalidate --tags=tag1,tag2 [--pool=default] [--apply] [--json] [--verbose]';
    }

    public function category(): string
    {
        return 'Cache';
    }

    public function aliases(): array
    {
        return ['cache:tags:invalidate', 'cache:flush-tags'];
    }

    public function optionsHelp(): array
    {
        return [
            '--tags=' => 'Lista de tags a invalidar, separados por coma (obligatorio).',
            '--pool=' => 'Nombre del pool sobre el que invalidar (por defecto: el pool por defecto configurado).',
            '--apply' => 'Ejecuta la invalidacion real. Sin este flag solo se muestra el plan (dry-run).',
            '--json' => 'Emite el plan o el resultado de la invalidacion en formato JSON.',
            '--verbose' => 'Muestra el detalle del diagnostico del repositorio en el plan.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $tagsOption = $input->option('tags');
        if ($tagsOption === null || $tagsOption === '' || $tagsOption === false) {
            $output->error('La opcion --tags es obligatoria. Especifica al menos un tag separado por comas.');

            return 1;
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $tagsOption))));

        if ($tags === []) {
            $output->error('No se especificaron tags validos.');

            return 1;
        }

        $apply = $input->hasOption('apply');
        $asJson = $input->hasOption('json');
        $verbose = $input->hasOption('verbose');
        $poolOption = $input->option('pool');

        return $this->runInCommandRuntime(function ($app) use ($apply, $asJson, $output, $poolOption, $tags, $verbose): int {
            $manager = $app->make(CacheManager::class);
            $config = $app->make(ConfigRepository::class);

            $pool = is_string($poolOption) && $poolOption !== ''
                ? $poolOption
                : (string) $config->get('cache.default_pool', $config->get('cache.default', 'file'));

            try {
                $plan = $manager->planInvalidateTags($pool, $tags);
            } catch (\Throwable $e) {
                $output->error(sprintf('No se pudo planificar la invalidacion: %s', $e->getMessage()));

                return 1;
            }

            if (! $apply) {
                if ($asJson) {
                    $output->write(json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    return 0;
                }

                $this->renderPlan($output, $plan, $verbose);
                $output->writeln('');
                $output->writeln('<comment>Esta es una simulacion (dry-run). Usa --apply para ejecutar la invalidacion.</comment>');

                return 0;
            }

            $repository = $manager->pool($pool)->tags($tags);
            $receipt = $repository->invalidateTagsReceipt($tags);
            $applied = $receipt->effect->value === 'applied';

            if ($asJson) {
                $output->write(json_encode([
                    'pool' => $pool,
                    'tags' => $tags,
                    'applied' => $applied,
                    'effect' => $receipt->effect->value,
                    'operation_id' => $receipt->operationId,
                    'write_id' => $receipt->writeId,
                    'details' => $receipt->details,
                    'plan' => $plan->toArray(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return $applied ? 0 : 1;
            }

            $output->writeln(sprintf('Invalidacion de tags en el pool <info>%s</info>', $pool));
            $output->writeln(sprintf('  tags:    %s', implode(', ', $tags)));
            $output->writeln(sprintf('  effect:  %s', $receipt->effect->value));
            $output->writeln(sprintf('  op_id:   %s', $receipt->operationId));
            $output->writeln(sprintf('  applied: %s', $applied ? 'si' : 'no'));

            if ($applied) {
                $output->writeln('<info>Tags invalidados correctamente.</info>');
            } else {
                $output->writeln('<comment>No se pudo invalidar (el pool no tiene authority de versiones).</comment>');
            }

            return $applied ? 0 : 1;
        });
    }

    /**
     * @param \Quantum\Cache\CacheInvalidationPlan $plan
     */
    private function renderPlan(Output $output, $plan, bool $verbose): void
    {
        $output->writeln(sprintf('Plan de invalidacion (dry-run) para el pool <info>%s</info>', $plan->pool));
        $output->writeln(sprintf('  schema:           %s', $plan->schemaVersion));
        $output->writeln(sprintf('  operation_id:     %s', $plan->operationId));
        $output->writeln(sprintf('  scope_fingerprint:%s', $plan->scopeFingerprint));
        $output->writeln(sprintf('  outcome:          %s', $plan->outcome));
        $output->writeln(sprintf('  apply_supported:  %s', $plan->applySupported ? 'si' : 'no'));

        $output->writeln('');
        $output->writeln('Efectos:');
        foreach ($plan->effects as $effect) {
            $output->writeln(sprintf('  - %s', $effect));
        }

        if ($plan->warnings !== []) {
            $output->writeln('');
            $output->writeln('Advertencias:');
            foreach ($plan->warnings as $warning) {
                $output->writeln(sprintf('  ! %s', $warning));
            }
        }

        $output->writeln('');
        $output->writeln('Objetivo:');
        foreach ($plan->target as $key => $value) {
            if (is_array($value)) {
                $output->writeln(sprintf('  %s:', $key));
                foreach ($value as $subKey => $subValue) {
                    $output->writeln(sprintf('    %s: %s', $subKey, var_export($subValue, true)));
                }
            } else {
                $output->writeln(sprintf('  %s: %s', $key, var_export($value, true)));
            }
        }

        if ($verbose) {
            $output->writeln('');
            $output->writeln('Diagnosticos:');
            foreach ($plan->diagnostics as $key => $value) {
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
    }
}
