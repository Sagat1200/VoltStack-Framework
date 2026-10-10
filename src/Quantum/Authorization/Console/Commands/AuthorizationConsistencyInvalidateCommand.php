<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
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
        return 'authz:consistency:invalidate [--domain=authority|relationships|all] [--principal-id=...] [--scope=...] [--reason=...] [--json] [--verbose] [--require-published-config]';
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
            '--reason=' => 'Motivo opcional de la invalidacion para auditoria operativa.',
            '--json' => 'Emite el resultado como JSON.',
            '--verbose' => 'Imprime detalles del driver de consistencia resuelto, contadores de bumps y ultimo timestamp por segmento.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de invalidar.',
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
            $app = $this->bootstrapApplication(requirePublishedConfig: $input->hasOption('require-published-config'));
        } catch (PublishedConfigurationRequiredException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la consistencia de Authorization: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        try {
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
        $reason = $this->stringOption($input, 'reason');

        $result = [];

        if ($domain === 'authority' || $domain === 'all') {
            $result['authority'] = $consistency->invalidateAuthority($principalId, $scope, $reason);
        }

        if ($domain === 'relationships' || $domain === 'all') {
            $result['relationships'] = $consistency->invalidateRelationships($principalId, $scope, $reason);
        }

        if ($input->hasOption('json')) {
            $payload = [
                'domain' => $domain,
                'principal_id' => $principalId,
                'scope' => $scope,
                'reason' => $reason,
                'result' => $result,
                'inspect' => $consistency->inspect(),
            ];

            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($input->hasOption('verbose')) {
            $output->writeln(sprintf('Driver de consistencia: %s', $consistency::class));

            if ($consistency instanceof VersionedAuthorizationConsistency) {
                $output->writeln(sprintf('Backend de versiones: %s', $consistency->driver()));
                $output->writeln(sprintf('Namespace: %s', $consistency->namespace()));

                foreach ($consistency->describeVersionAuthority() as $key => $value) {
                    $output->writeln(sprintf('  - %s => %s', $key, is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES)));
                }

                $lastBumpAt = $consistency->lastBumpAt();
                $output->writeln(sprintf('  - last_bump_at => %s', $lastBumpAt ?? '-'));

                foreach ($consistency->bumpCounters() as $segment => $count) {
                    $output->writeln(sprintf('  - bump_counter.%s => %d', $segment, $count));
                }
            }

            if (is_string($reason) && $reason !== '') {
                $output->writeln(sprintf('Reason: %s', $reason));
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
