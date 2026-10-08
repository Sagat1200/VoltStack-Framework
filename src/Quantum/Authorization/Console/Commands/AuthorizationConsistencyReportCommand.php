<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationConsistencyReportCommand extends Command
{
    public function name(): string
    {
        return 'authz:consistency:report';
    }

    public function description(): string
    {
        return 'Inspecciona las versiones activas de consistencia para authority y relaciones.';
    }

    public function usage(): string
    {
        return 'authz:consistency:report [--principal-id=...] [--scope=...] [--json] [--verbose]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:consistency:report', 'authz:report-consistency'];
    }

    public function optionsHelp(): array
    {
        return [
            '--principal-id=' => 'Principal opcional para inspeccionar segmentos principal/principal_scope.',
            '--scope=' => 'Scope opcional para inspeccionar segmentos scope/principal_scope.',
            '--json' => 'Emite el reporte como JSON.',
            '--verbose' => 'Imprime metadatos adicionales del backend de consistencia resuelto.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $app = $this->bootstrapApplication();
            $consistency = $app->make(AuthorizationConsistencyInterface::class);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la consistencia de Authorization: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        $principalId = $this->stringOption($input, 'principal-id');
        $scope = $this->stringOption($input, 'scope');

        $payload = [
            'driver' => $consistency::class,
            'principal_id' => $principalId,
            'scope' => $scope,
            'authority' => $this->authoritySnapshot($consistency, $principalId, $scope),
            'relationships' => $this->relationshipSnapshot($consistency, $principalId, $scope),
        ];

        if ($consistency instanceof VersionedAuthorizationConsistency) {
            $payload['backend'] = $consistency->driver();
            $payload['namespace'] = $consistency->namespace();
        }

        if ($input->hasOption('json')) {
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($input->hasOption('verbose')) {
            $output->writeln(sprintf('Driver de consistencia: %s', $payload['driver']));

            if (isset($payload['backend']) && is_string($payload['backend'])) {
                $output->writeln(sprintf('Backend de versiones: %s', $payload['backend']));
            }

            if (isset($payload['namespace']) && is_string($payload['namespace'])) {
                $output->writeln(sprintf('Namespace: %s', $payload['namespace']));
            }
        }

        $output->writeln('Reporte de consistencia Authorization:');
        $output->writeln(sprintf('  principal_id: %s', $principalId ?? '-'));
        $output->writeln(sprintf('  scope: %s', $scope ?? '-'));

        foreach (['authority', 'relationships'] as $domain) {
            $output->writeln(sprintf('  %s:', $domain));

            foreach ((array) $payload[$domain] as $segment => $version) {
                $output->writeln(sprintf('    - %s => %s', $segment, $version));
            }
        }

        return 0;
    }

    /**
     * @return array<string, string>
     */
    private function authoritySnapshot(AuthorizationConsistencyInterface $consistency, ?string $principalId, ?string $scope): array
    {
        if ($consistency instanceof VersionedAuthorizationConsistency) {
            return $consistency->describeAuthority($principalId, $scope);
        }

        if ($principalId !== null && $scope !== null) {
            return [
                'composite' => $consistency->authorityVersion($principalId, $scope),
            ];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function relationshipSnapshot(AuthorizationConsistencyInterface $consistency, ?string $principalId, ?string $scope): array
    {
        if ($consistency instanceof VersionedAuthorizationConsistency) {
            return $consistency->describeRelationships($principalId, $scope);
        }

        if ($principalId !== null && $scope !== null) {
            return [
                'composite' => $consistency->relationshipVersion($principalId, $scope),
            ];
        }

        return [];
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
}
