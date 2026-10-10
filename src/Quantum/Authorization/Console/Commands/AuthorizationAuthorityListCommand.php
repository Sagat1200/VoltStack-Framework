<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Authorization\Contracts\DelegationAdministrationInterface;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationAuthorityListCommand extends Command
{
    public function name(): string
    {
        return 'authz:authority:list';
    }

    public function description(): string
    {
        return 'Lista grants RBAC/directos desde el repositorio authority configurado.';
    }

    public function usage(): string
    {
        return 'authz:authority:list [--principal-id=...] [--scope=...] [--type=role|permission|all] [--value=...] [--view=simple|delegations|all] [--json] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:authority:list', 'authz:list-authority'];
    }

    public function optionsHelp(): array
    {
        return [
            '--principal-id=' => 'Filtra por principal_id exacto.',
            '--scope=' => 'Filtra por scope exacto.',
            '--type=' => 'role, permission o all. Default: all.',
            '--value=' => 'Filtra por nombre exacto del role o permission.',
            '--view=' => 'simple (solo authority, default), delegations (solo delegation grants), all (fusiona ambos).',
            '--json' => 'Imprime el resultado como JSON en stdout.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de listar authority.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $type = strtolower(trim((string) ($input->option('type', 'all') ?? 'all')));
        $view = strtolower(trim((string) ($input->option('view', 'simple') ?? 'simple')));

        if (! in_array($type, ['role', 'permission', 'all'], true)) {
            $output->error('La opcion --type debe ser role, permission o all.');

            return 1;
        }

        if (! in_array($view, ['simple', 'delegations', 'all'], true)) {
            $output->error('La opcion --view debe ser simple, delegations o all.');

            return 1;
        }

        try {
            $app = $this->bootstrapApplication(requirePublishedConfig: $input->hasOption('require-published-config'));
        } catch (PublishedConfigurationRequiredException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la administracion de authority: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        try {
            $admin = $app->make(AuthorityAdministrationInterface::class);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la administracion de authority: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        $filters = array_filter([
            'principal_id' => $this->stringOption($input, 'principal-id'),
            'scope' => $this->stringOption($input, 'scope'),
            'type' => $type,
            'value' => $this->stringOption($input, 'value'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        $grants = [];

        if ($view === 'simple' || $view === 'all') {
            try {
                $grants = $admin->listGrants($filters);
            } catch (\Throwable $exception) {
                $output->error(sprintf(
                    'No se pudieron listar los grants de authority: %s',
                    $exception->getMessage(),
                ));

                return 1;
            }
        }

        $delegationGrants = [];

        if ($view === 'delegations' || $view === 'all') {
            try {
                /** @var DelegationAdministrationInterface $delegationAdmin */
                $delegationAdmin = $app->make(DelegationAdministrationInterface::class);
            } catch (\Throwable $exception) {
                $output->error(sprintf(
                    'No se pudo resolver la administracion de delegation: %s',
                    $exception->getMessage(),
                ));

                return 1;
            }

            $delegationFilters = array_filter([
                'trustee_id' => $filters['principal_id'] ?? null,
                'grantor_id' => $this->stringOption($input, 'grantor-id'),
                'scope' => $filters['scope'] ?? null,
                'type' => $filters['type'] ?? 'all',
                'value' => $filters['value'] ?? null,
            ], static fn (mixed $value): bool => is_string($value) && $value !== '');

            try {
                $delegationGrants = $delegationAdmin->listDelegations($delegationFilters);
            } catch (\Throwable $exception) {
                $output->error(sprintf(
                    'No se pudieron listar los grants de delegation: %s',
                    $exception->getMessage(),
                ));

                return 1;
            }

            // Normaliza formas alineadas con el formato de authority list para la vista fusionada.
            $delegationGrants = array_map(static function (array $g): array {
                return [
                    'principal_id' => $g['trustee_id'] ?? '',
                    'trustee_id' => $g['trustee_id'] ?? '',
                    'grantor_id' => $g['grantor_id'] ?? '',
                    'scope' => $g['scope'] ?? '',
                    'type' => ($g['type'] ?? '') . '_delegation',
                    'value' => $g['value'] ?? '',
                    'granted_at' => $g['granted_at'] ?? null,
                    'kind' => 'delegation',
                ];
            }, $delegationGrants);
        }

        $outputGrants = array_merge($grants, $delegationGrants);

        if ($input->hasOption('json')) {
            $output->writeln((string) json_encode($outputGrants, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($outputGrants === []) {
            $output->writeln('No se encontraron grants con los filtros proporcionados.');

            return 0;
        }

        $output->writeln(sprintf('Grants encontrados: %d', count($outputGrants)));

        foreach ($outputGrants as $index => $grant) {
            if (($grant['kind'] ?? null) === 'delegation') {
                $output->writeln(sprintf(
                    '  [%d] [DELEGATION] trustee=%s grantor=%s scope=%s type=%s value=%s',
                    $index + 1,
                    $grant['trustee_id'] ?? '',
                    $grant['grantor_id'] ?? '',
                    $grant['scope'] ?? '',
                    str_replace('_delegation', '', $grant['type'] ?? ''),
                    $grant['value'] ?? '',
                ));
            } else {
                $output->writeln(sprintf(
                    '  [%d] principal=%s scope=%s type=%s value=%s',
                    $index + 1,
                    $grant['principal_id'] ?? '',
                    $grant['scope'] ?? '',
                    $grant['type'] ?? '',
                    $grant['value'] ?? '',
                ));
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
