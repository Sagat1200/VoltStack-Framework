<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationAuthorityRevokeCommand extends Command
{
    public function name(): string
    {
        return 'authz:authority:revoke';
    }

    public function description(): string
    {
        return 'Revoca un role o permission exacto del principal indicado.';
    }

    public function usage(): string
    {
        return 'authz:authority:revoke --principal-id=... [--scope=global] (--role=...|--permission=...) [--dry-run] [--verbose]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:authority:revoke', 'authz:revoke-authority'];
    }

    public function optionsHelp(): array
    {
        return [
            '--principal-id=' => 'Principal al que se le revocara el grant.',
            '--scope=' => 'Scope exacto del grant. Default: global.',
            '--role=' => 'Role exacto a revocar.',
            '--permission=' => 'Permission exacto a revocar.',
            '--dry-run' => 'Muestra la operacion sin modificar el repositorio.',
            '--verbose' => 'Imprime el repositorio administrativo utilizado.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $principalId = $this->stringOption($input, 'principal-id');
        $scope = $this->stringOption($input, 'scope') ?? 'global';
        [$type, $value, $error] = $this->selection($input);

        if ($principalId === null || $type === null || $value === null) {
            $output->error($error ?? 'Debes indicar --principal-id y exactamente uno entre --role o --permission.');

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

        if ($input->hasOption('dry-run')) {
            $output->writeln('[dry-run] El grant seria revocado.');
            $output->writeln(sprintf(
                '  principal=%s scope=%s type=%s value=%s',
                $principalId,
                $scope,
                $type,
                $value,
            ));

            return 0;
        }

        try {
            $revoked = $type === 'role'
                ? $admin->revokeRole($principalId, $value, $scope)
                : $admin->revokePermission($principalId, $value, $scope);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo revocar el grant de authority: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if ($input->hasOption('verbose')) {
            $output->writeln(sprintf('  Repositorio administrativo: %s', $admin::class));
        }

        if (! $revoked) {
            $output->writeln('No se encontro un grant coincidente para revocar.');

            return 0;
        }

        $output->writeln('Grant de authority revocado correctamente.');
        $output->writeln(sprintf(
            '  principal=%s scope=%s type=%s value=%s',
            $principalId,
            $scope,
            $type,
            $value,
        ));

        return 0;
    }

    /**
     * @return array{0:?string,1:?string,2:?string}
     */
    private function selection(Input $input): array
    {
        $role = $this->stringOption($input, 'role');
        $permission = $this->stringOption($input, 'permission');

        if (($role === null && $permission === null) || ($role !== null && $permission !== null)) {
            return [null, null, 'Debes indicar exactamente uno entre --role o --permission.'];
        }

        return $role !== null
            ? ['role', $role, null]
            : ['permission', $permission, null];
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
