<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Contracts\DelegationAdministrationInterface;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationDelegationRevokeCommand extends Command
{
    public function name(): string
    {
        return 'authz:delegation:revoke';
    }

    public function description(): string
    {
        return 'Revoca un role o permission de delegation exacto.';
    }

    public function usage(): string
    {
        return 'authz:delegation:revoke --trustee-id=... --grantor-id=... [--scope=global] (--role=...|--permission=...) [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:delegation:revoke', 'authz:delegate:revoke', 'authz:revoke-delegation'];
    }

    public function optionsHelp(): array
    {
        return [
            '--trustee-id=' => 'Principal trustee del grant a revocar.',
            '--grantor-id=' => 'Principal grantor del grant a revocar.',
            '--scope=' => 'Scope exacto. Default: global.',
            '--role=' => 'Role exacto a revocar.',
            '--permission=' => 'Permission exacta a revocar.',
            '--verbose' => 'Imprime el repositorio administrativo utilizado y el resultado.',
            '--require-published-config' => 'Exige configuracion publicada sin drift antes de revocar el grant.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $trusteeId = $this->stringOption($input, 'trustee-id');
        $grantorId = $this->stringOption($input, 'grantor-id');
        $role = $this->stringOption($input, 'role');
        $permission = $this->stringOption($input, 'permission');
        $scope = $this->stringOption($input, 'scope') ?? 'global';

        if ($trusteeId === null || $trusteeId === '') {
            $output->error('Se requiere --trustee-id.');

            return 1;
        }

        if ($grantorId === null || $grantorId === '') {
            $output->error('Se requiere --grantor-id.');

            return 1;
        }

        if (($role === null) === ($permission === null)) {
            $output->error('Se requiere exactamente una opcion entre --role y --permission.');

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

        $grant = $role !== null ? new Role($role) : Permission::from((string) $permission);

        try {
            $revoked = $admin->revokeDelegation($trusteeId, $grantorId, $grant, $scope);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo revocar el grant de delegation: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if (! $revoked) {
            if ($input->hasOption('verbose')) {
                $output->writeln('No se encontro ningun grant de delegation que revocar (no-op).');
            }

            return 0;
        }

        $output->writeln(sprintf(
            'Grant de delegation revocado: trustee=%s grantor=%s scope=%s type=%s value=%s',
            $trusteeId,
            $grantorId,
            $scope,
            $role !== null ? 'role' : 'permission',
            $role ?? (string) $permission,
        ));

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
