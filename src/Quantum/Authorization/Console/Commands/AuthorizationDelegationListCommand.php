<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Contracts\DelegationAdministrationInterface;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationDelegationListCommand extends Command
{
    public function name(): string
    {
        return 'authz:delegation:list';
    }

    public function description(): string
    {
        return 'Lista grants de delegation (trustee -> grantor -> role/permission) desde el repositorio authority configurado.';
    }

    public function usage(): string
    {
        return 'authz:delegation:list [--trustee-id=...] [--grantor-id=...] [--scope=...] [--type=role|permission|all] [--value=...] [--json] [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:delegation:list', 'authz:delegate:list', 'authz:list-delegations'];
    }

    public function optionsHelp(): array
    {
        return [
            '--trustee-id=' => 'Filtra por trustee (principal que actua en nombre del grantor).',
            '--grantor-id=' => 'Filtra por grantor (principal en nombre del cual actua el trustee).',
            '--scope=' => 'Filtra por scope exacto.',
            '--type=' => 'role, permission o all. Default: all.',
            '--value=' => 'Filtra por nombre exacto del role o permission.',
            '--json' => 'Imprime el resultado como JSON en stdout.',
            '--verbose' => 'Imprime el repositorio administrativo utilizado.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de listar delegations.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $type = strtolower(trim((string) ($input->option('type', 'all') ?? 'all')));

        if (! in_array($type, ['role', 'permission', 'all'], true)) {
            $output->error('La opcion --type debe ser role, permission o all.');

            return 1;
        }

        try {
            $app = $this->bootstrapApplication(requirePublishedConfig: $input->hasOption('require-published-config'));
        } catch (PublishedConfigurationRequiredException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la administracion de delegation: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        try {
            /** @var DelegationAdministrationInterface $admin */
            $admin = $app->make(DelegationAdministrationInterface::class);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la administracion de delegation: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if ($input->hasOption('verbose')) {
            $output->writeln(sprintf('Repositorio administrativo: %s', $admin::class));
        }

        $filters = array_filter([
            'trustee_id' => $this->stringOption($input, 'trustee-id'),
            'grantor_id' => $this->stringOption($input, 'grantor-id'),
            'scope' => $this->stringOption($input, 'scope'),
            'type' => $type,
            'value' => $this->stringOption($input, 'value'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        try {
            $grants = $admin->listDelegations($filters);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudieron listar los grants de delegation: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if ($input->hasOption('json')) {
            $output->writeln((string) json_encode($grants, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($grants === []) {
            $output->writeln('No se encontraron grants de delegation con los filtros proporcionados.');

            return 0;
        }

        $output->writeln(sprintf('Grants de delegation encontrados: %d', count($grants)));

        foreach ($grants as $index => $grant) {
            $output->writeln(sprintf(
                '  [%d] trustee=%s grantor=%s scope=%s type=%s value=%s granted_at=%s',
                $index + 1,
                $grant['trustee_id'] ?? '',
                $grant['grantor_id'] ?? '',
                $grant['scope'] ?? '',
                $grant['type'] ?? '',
                $grant['value'] ?? '',
                $grant['granted_at'] ?? 'null',
            ));
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
