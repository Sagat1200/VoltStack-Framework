<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationConsistencyInvalidateCommand extends Command
{
    public function name(): string
    {
        return 'authz:consistency:invalidate';
    }

    public function description(): string
    {
        return 'Invalida generaciones de consistencia para authority y relaciones.';
    }

    public function usage(): string
    {
        return 'authz:consistency:invalidate [--domain=authority|relationships|all] [--principal-id=...] [--scope=...] [--json] [--verbose]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:consistency:invalidate', 'authz:invalidate-consistency'];
    }

    public function optionsHelp(): array
    {
        return [
            '--domain=' => 'authority, relationships o all. Default: all.',
            '--principal-id=' => 'Invalida por principal concreto.',
            '--scope=' => 'Invalida por scope concreto.',
            '--json' => 'Emite el resultado como JSON.',
            '--verbose' => 'Imprime detalles del driver de consistencia resuelto.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $domain = strtolower(trim((string) ($input->option('domain', 'all') ?? 'all')));

        if (! in_array($domain, ['authority', 'relationships', 'all'], true)) {
            $output->error('La opcion --domain debe ser authority, relationships o all.');

            return 1;
        }

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

        $result = [];

        if ($domain === 'authority' || $domain === 'all') {
            $result['authority'] = $consistency->invalidateAuthority($principalId, $scope);
        }

        if ($domain === 'relationships' || $domain === 'all') {
            $result['relationships'] = $consistency->invalidateRelationships($principalId, $scope);
        }

        if ($input->hasOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($input->hasOption('verbose')) {
            $output->writeln(sprintf('Driver de consistencia: %s', $consistency::class));

            if ($consistency instanceof VersionedAuthorizationConsistency) {
                $output->writeln(sprintf('Backend de versiones: %s', $consistency->driver()));
                $output->writeln(sprintf('Namespace: %s', $consistency->namespace()));
            }
        }

        $output->writeln(sprintf('Invalidacion ejecutada sobre dominio: %s', $domain));

        foreach ($result as $name => $versions) {
            $output->writeln(sprintf('  %s:', $name));

            foreach ($versions as $segment => $version) {
                $output->writeln(sprintf('    - %s => %s', $segment, $version));
            }
        }

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
}
