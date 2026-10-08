<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
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
        return 'authz:authority:list [--principal-id=...] [--scope=...] [--type=role|permission|all] [--value=...] [--json]';
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
            '--json' => 'Imprime el resultado como JSON en stdout.',
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
            $app = $this->bootstrapApplication();
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

        try {
            $grants = $admin->listGrants($filters);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudieron listar los grants de authority: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if ($input->hasOption('json')) {
            $output->writeln((string) json_encode($grants, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($grants === []) {
            $output->writeln('No se encontraron grants de authority con los filtros proporcionados.');

            return 0;
        }

        $output->writeln(sprintf('Grants encontrados: %d', count($grants)));

        foreach ($grants as $index => $grant) {
            $output->writeln(sprintf(
                '  [%d] principal=%s scope=%s type=%s value=%s',
                $index + 1,
                $grant['principal_id'],
                $grant['scope'],
                $grant['type'],
                $grant['value'],
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
