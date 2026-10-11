<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

/**
 * Comando observable de flush selectivo distribuido del backend de consistencia.
 *
 * A diferencia de authz:consistency:invalidate (que invalida generaciones),
 * este comando se enfoca en observabilidad: siempre devuelve
 * invalidated_segments, consistency_driver y flush_metrics.
 */
final class AuthorizationConsistencyFlushCommand extends Command
{
    public function name(): string
    {
        return 'authz:consistency:flush';
    }

    public function description(): string
    {
        return 'Flush selectivo y observable del backend de consistencia de Authorization (authority/relationships).';
    }

    public function usage(): string
    {
        return 'authz:consistency:flush [--domain=authority|relationships|both] [--principal=...] [--scope=...] [--reason=...] [--dry-run] [--json] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:consistency:flush', 'authz:flush-consistency'];
    }

    public function optionsHelp(): array
    {
        return [
            '--domain=' => 'authority, relationships o both. Default: both.',
            '--principal=' => 'Flush por principal concreto.',
            '--scope=' => 'Flush por scope concreto.',
            '--reason=' => 'Motivo opcional para auditoria operativa.',
            '--dry-run' => 'No invalida; solo proyecta los segmentos que se tocarian y sus versiones actuales.',
            '--json' => 'Emite el resultado como JSON.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de flushear.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $domain = strtolower(trim((string) ($input->option('domain', 'both') ?? 'both')));

        if (! in_array($domain, ['authority', 'relationships', 'both'], true)) {
            $output->error('La opcion --domain debe ser authority, relationships o both.');

            return 1;
        }

        try {
            $app = $this->bootstrapApplication(requirePublishedConfig: $input->hasOption('require-published-config'));
        } catch (PublishedConfigurationRequiredException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver el flush de consistencia de Authorization: %s',
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

        $principalId = $this->stringOption($input, 'principal');
        $scope = $this->stringOption($input, 'scope');
        $reason = $this->stringOption($input, 'reason') ?? 'consistency.flush';
        $dryRun = $input->hasOption('dry-run');
        $consistencyDriver = $consistency::class;

        if ($dryRun) {
            $wouldFlush = $this->buildDryRunProjection($consistency, $domain, $principalId, $scope);

            if ($input->hasOption('json')) {
                $payload = [
                    'ok' => true,
                    'domain' => $domain,
                    'principal_id' => $principalId,
                    'scope' => $scope,
                    'reason' => $reason,
                    'dry_run' => true,
                    'consistency_driver' => $consistencyDriver,
                    'would_flush' => $wouldFlush,
                    'flush_metrics' => $consistency->flushMetrics(),
                ];

                $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            $output->writeln('Dry-run: no se ejecutaron flushes.');
            $output->writeln(sprintf('Dominio: %s', $domain));
            $output->writeln(sprintf('Driver: %s', $consistencyDriver));

            foreach ($wouldFlush as $dom => $segments) {
                $output->writeln(sprintf('  %s:', $dom));
                foreach ($segments as $segment => $version) {
                    $output->writeln(sprintf('    - %s => %s', $segment, $version));
                }
            }

            return 0;
        }

        $invalidatedSegments = [];

        try {
            if ($domain === 'authority' || $domain === 'both') {
                $invalidatedSegments['authority'] = $consistency->invalidateAuthority($principalId, $scope, $reason);
            }

            if ($domain === 'relationships' || $domain === 'both') {
                $invalidatedSegments['relationships'] = $consistency->invalidateRelationships($principalId, $scope, $reason);
            }
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo ejecutar el flush de consistencia: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        $flushMetrics = $consistency->flushMetrics();

        if ($input->hasOption('json')) {
            $payload = [
                'ok' => true,
                'domain' => $domain,
                'principal_id' => $principalId,
                'scope' => $scope,
                'reason' => $reason,
                'consistency_driver' => $consistencyDriver,
                'invalidated_segments' => $invalidatedSegments,
                'flush_metrics' => $flushMetrics,
            ];

            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $output->writeln(sprintf('Flush de consistencia ejecutado sobre dominio: %s', $domain));
        $output->writeln(sprintf('Driver: %s', $consistencyDriver));

        foreach ($invalidatedSegments as $name => $versions) {
            $output->writeln(sprintf('  %s:', $name));

            foreach ($versions as $segment => $version) {
                $output->writeln(sprintf('    - %s => %s', $segment, $version));
            }
        }

        $totalBumps = (int) ($flushMetrics['total_bumps'] ?? 0);
        $output->writeln(sprintf('Total bumps (flush_metrics): %d', $totalBumps));

        return 0;
    }

    private function stringOption(Input $input, string $name): ?string
    {
        $value = $input->option($name);

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Proyecta las versiones actuales de los segmentos que se flushearían,
     * sin ejecutar ningun bump.
     *
     * @return array<string, array<string, string>>
     */
    private function buildDryRunProjection(
        AuthorizationConsistencyInterface $consistency,
        string $domain,
        ?string $principalId,
        ?string $scope,
    ): array {
        $result = [];

        if ($domain === 'authority' || $domain === 'both') {
            $version = $consistency->authorityVersion($principalId ?? '*', $scope ?? 'GLOBAL');
            $result['authority'] = [$this->segmentKey($principalId, $scope) => $version];
        }

        if ($domain === 'relationships' || $domain === 'both') {
            $version = $consistency->relationshipVersion($principalId ?? '*', $scope ?? 'GLOBAL');
            $result['relationships'] = [$this->segmentKey($principalId, $scope) => $version];
        }

        return $result;
    }

    private function segmentKey(?string $principalId, ?string $scope): string
    {
        $principal = $principalId ?? '*';
        $scopeNormalized = $scope ?? 'GLOBAL';

        return sprintf('%s@%s', $principal, $scopeNormalized);
    }
}
