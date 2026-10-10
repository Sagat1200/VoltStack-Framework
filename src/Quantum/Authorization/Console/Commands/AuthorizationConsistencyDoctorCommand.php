<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationConsistencyDoctorCommand extends Command
{
    public function name(): string
    {
        return 'authz:consistency:doctor';
    }

    public function description(): string
    {
        return 'Diagnostico operativo del backend de consistencia de Authorization: driver, store, namespace y auditoria de bumps.';
    }

    public function usage(): string
    {
        return 'authz:consistency:doctor [--json] [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:consistency:doctor', 'authz:doctor-consistency'];
    }

    public function optionsHelp(): array
    {
        return [
            '--json' => 'Emite el diagnostico como JSON.',
            '--verbose' => 'Incluye desglose de segmentos y razones observadas en el proceso actual.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de generar el diagnostico.',
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
                'No se pudo resolver el doctor de consistencia de Authorization: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        try {
            $consistency = $app->make(AuthorizationConsistencyInterface::class);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver el driver de consistencia de Authorization: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        try {
            $inspect = $consistency->inspect();
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo inspeccionar el driver de consistencia: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if (! is_array($inspect)) {
            $output->error('El driver de consistencia no devolvio un payload de inspeccion valido.');

            return 1;
        }

        $implementation = $consistency::class;
        $isVersioned = $consistency instanceof VersionedAuthorizationConsistency;

        $bumpReasons = $this->flattenBumpReasons($inspect);

        $payload = [
            'ok' => true,
            'implementation' => $implementation,
            'is_versioned' => $isVersioned,
            'delegation_bumps' => $this->countReasonsByPrefix($bumpReasons, 'delegation.'),
            'service_principal_bumps' => $this->countReasonsByPrefix($bumpReasons, 'service.'),
            'consistency_driver_config' => [
                'driver' => is_string($app->config('authorization.consistency.driver')) ? (string) $app->config('authorization.consistency.driver') : null,
                'namespace' => is_string($app->config('authorization.consistency.namespace')) ? (string) $app->config('authorization.consistency.namespace') : null,
                'file' => [
                    'path' => is_string($app->config('authorization.consistency.file.path')) ? (string) $app->config('authorization.consistency.file.path') : null,
                ],
                'cache' => [
                    'store' => is_string($app->config('authorization.consistency.cache.store')) ? (string) $app->config('authorization.consistency.cache.store') : null,
                    'prefix' => is_string($app->config('authorization.consistency.cache.prefix')) ? (string) $app->config('authorization.consistency.cache.prefix') : null,
                    'ttl_seconds' => $app->config('authorization.consistency.cache.ttl_seconds'),
                ],
            ],
            'inspect' => $inspect,
        ];

        if ($input->hasOption('json')) {
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $output->writeln('Authorization consistency doctor:');
        $output->writeln(sprintf('  implementation: %s', $implementation));
        $output->writeln(sprintf('  versioned: %s', $isVersioned ? 'yes' : 'no'));

        foreach ($payload['consistency_driver_config'] as $section => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $printedValue = is_scalar($subValue)
                        ? ($subValue === null ? 'null' : (string) $subValue)
                        : (string) json_encode($subValue, JSON_UNESCAPED_SLASHES);
                    $output->writeln(sprintf('  config.%s.%s => %s', $section, $subKey, $printedValue));
                }

                continue;
            }

            $output->writeln(sprintf('  config.%s => %s', $section, $value ?? 'null'));
        }

        if (isset($inspect['version_authority_info']) && is_array($inspect['version_authority_info'])) {
            $output->writeln('  version_authority_info:');
            foreach ($inspect['version_authority_info'] as $key => $value) {
                $printedValue = is_scalar($value)
                    ? ($value === null ? 'null' : (string) $value)
                    : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
                $output->writeln(sprintf('    - %s => %s', $key, $printedValue));
            }
        }

        if (isset($inspect['last_bump_at']) && is_string($inspect['last_bump_at'])) {
            $output->writeln(sprintf('  last_bump_at: %s', $inspect['last_bump_at']));
        } elseif ($isVersioned) {
            $output->writeln(sprintf('  last_bump_at: %s', $consistency->lastBumpAt() ?? '-'));
        } else {
            $output->writeln('  last_bump_at: -');
        }

        $output->writeln(sprintf('  delegation_bumps: %d', (int) $payload['delegation_bumps']));
        $output->writeln(sprintf('  service_principal_bumps: %d', (int) $payload['service_principal_bumps']));

        if ($isVersioned) {
            if ($input->hasOption('verbose')) {
                $counters = $consistency->bumpCounters();
                $reasons = $consistency->bumpReasons();

                if ($counters === []) {
                    $output->writeln('  bump counters: none');
                } else {
                    $output->writeln('  bump counters:');
                    foreach ($counters as $segment => $count) {
                        $reason = $reasons[$segment] ?? '';
                        $reasonSuffix = $reason !== '' ? sprintf(' [reason: %s]', $reason) : '';
                        $output->writeln(sprintf('    - %s => %d%s', $segment, $count, $reasonSuffix));
                    }
                }
            }

            return 0;
        }

        if ($input->hasOption('verbose')) {
            $counters = $inspect['bump_counters_by_segment'] ?? null;
            $reasons = $inspect['last_bump_reasons_by_segment'] ?? null;

            if (! is_array($counters) || $counters === []) {
                $output->writeln('  bump counters: none');
            } else {
                $output->writeln('  bump counters:');
                foreach ($counters as $segment => $count) {
                    $reasonText = is_array($reasons) && isset($reasons[$segment]) && is_string($reasons[$segment]) && $reasons[$segment] !== ''
                        ? sprintf(' [reason: %s]', $reasons[$segment])
                        : '';
                    $printedCount = is_int($count) ? (string) $count : (string) $count;
                    $output->writeln(sprintf('    - %s => %s%s', $segment, $printedCount, $reasonText));
                }
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $inspect
     * @return list<string>
     */
    private function flattenBumpReasons(array $inspect): array
    {
        $reasons = [];

        $bySegment = $inspect['last_bump_reasons_by_segment'] ?? null;
        if (is_array($bySegment)) {
            foreach ($bySegment as $list) {
                if (! is_array($list)) {
                    continue;
                }
                foreach ($list as $reason) {
                    if (is_string($reason) && trim($reason) !== '') {
                        $reasons[] = $reason;
                    }
                }
            }
        }

        return $reasons;
    }

    /**
     * @param list<string> $reasons
     */
    private function countReasonsByPrefix(array $reasons, string $prefix): int
    {
        $count = 0;
        foreach ($reasons as $reason) {
            if (str_starts_with($reason, $prefix)) {
                $count++;
            }
        }

        return $count;
    }
}
