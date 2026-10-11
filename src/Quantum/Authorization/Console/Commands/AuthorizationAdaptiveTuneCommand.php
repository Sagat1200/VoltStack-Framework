<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Core\Stages\AdaptiveAccessRuntimeConfig;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationAdaptiveTuneCommand extends Command
{
    public function name(): string
    {
        return 'authz:adaptive:tune';
    }

    public function description(): string
    {
        return 'Inspecciona o ajusta en runtime los umbrales de Adaptive Access.';
    }

    public function usage(): string
    {
        return 'authz:adaptive:tune [--apply] [--step-up-threshold=N] [--deny-threshold=N] [--allow-threshold=N] [--policy=dim:key:field=value] [--json] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:adaptive:tune', 'authz:tune-adaptive'];
    }

    public function optionsHelp(): array
    {
        return [
            '--apply' => 'Aplica los overrides indicados en runtime. Sin este flag el comando es read-only.',
            '--step-up-threshold=' => 'Override del umbral de step-up (entero o null para limpiar).',
            '--deny-threshold=' => 'Override del umbral de deny (entero o null para limpiar).',
            '--allow-threshold=' => 'Override del umbral de allow (entero o null para limpiar).',
            '--policy=' => 'Override por dimensión, formato <dim>:<key>:<field>=<value> (ej: operation:posts.edit:deny_threshold=30).',
            '--json' => 'Emite el resultado como JSON.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $app = $this->bootstrapApplication(requirePublishedConfig: $input->hasOption('require-published-config'));
        } catch (PublishedConfigurationRequiredException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la configuracion de Adaptive Access: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        $config = $app->config('authorization.adaptive_access', []);
        $config = is_array($config) ? $config : [];
        $tuneEnabled = (bool) ($app->config('authorization.adaptive.tune.enabled', false));

        $runtimeConfig = null;
        try {
            $runtimeConfig = $app->make(AdaptiveAccessRuntimeConfig::class);
        } catch (\Throwable) {
            $runtimeConfig = null;
        }

        $apply = $input->hasOption('apply');

        if ($apply && ! $tuneEnabled) {
            $output->error('El modo --apply requiere que authorization.adaptive.tune.enabled sea true.');

            return 1;
        }

        $applied = [];

        if ($apply && $runtimeConfig !== null) {
            $stepUp = $this->nullableIntOption($input, 'step-up-threshold');
            $deny = $this->nullableIntOption($input, 'deny-threshold');
            $allow = $this->nullableIntOption($input, 'allow-threshold');

            if ($input->hasOption('step-up-threshold')) {
                $runtimeConfig->setStepUpThreshold($stepUp);
                $applied['step_up_threshold'] = $stepUp;
            }
            if ($input->hasOption('deny-threshold')) {
                $runtimeConfig->setDenyThreshold($deny);
                $applied['deny_threshold'] = $deny;
            }
            if ($input->hasOption('allow-threshold')) {
                $runtimeConfig->setAllowThreshold($allow);
                $applied['allow_threshold'] = $allow;
            }

            $policyOpt = $input->option('policy');
            if (is_string($policyOpt) && trim($policyOpt) !== '') {
                $applied['policy'] = $policyOpt;
            }
        }

        $enabled = (bool) ($config['enabled'] ?? false);
        $policies = is_array($config['policies'] ?? null) ? $config['policies'] : [];

        $runtimeOverrides = $runtimeConfig !== null ? $runtimeConfig->toArray() : [
            'step_up_threshold' => null,
            'deny_threshold' => null,
            'allow_threshold' => null,
        ];

        $payload = [
            'enabled' => $enabled,
            'step_up_threshold' => $runtimeOverrides['step_up_threshold'] ?? ($config['step_up_threshold'] ?? null),
            'deny_threshold' => $runtimeOverrides['deny_threshold'] ?? ($config['deny_threshold'] ?? null),
            'allow_threshold' => $runtimeOverrides['allow_threshold'] ?? ($config['allow_threshold'] ?? null),
            'policies_count' => count($policies),
            'policies' => array_keys($policies),
            'runtime_overrides' => $runtimeOverrides,
            'tune_enabled' => $tuneEnabled,
            'applied' => $applied,
        ];

        if ($input->hasOption('json')) {
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $output->writeln(sprintf('Adaptive Access habilitado: %s', $enabled ? 'si' : 'no'));
        $output->writeln(sprintf('  step_up_threshold: %s', var_export($payload['step_up_threshold'], true)));
        $output->writeln(sprintf('  deny_threshold: %s', var_export($payload['deny_threshold'], true)));
        $output->writeln(sprintf('  allow_threshold: %s', var_export($payload['allow_threshold'], true)));
        $output->writeln(sprintf('  policies: %d', $payload['policies_count']));

        if ($apply) {
            $output->writeln('Overrides aplicados en runtime:');
            foreach ($applied as $key => $value) {
                $output->writeln(sprintf('  - %s => %s', $key, var_export($value, true)));
            }
        }

        return 0;
    }

    private function nullableIntOption(Input $input, string $name): ?int
    {
        $value = $input->option($name);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || strtolower($trimmed) === 'null') {
                return null;
            }
            if (preg_match('/^-?\d+$/', $trimmed) === 1) {
                return (int) $trimmed;
            }
        }

        return null;
    }
}
