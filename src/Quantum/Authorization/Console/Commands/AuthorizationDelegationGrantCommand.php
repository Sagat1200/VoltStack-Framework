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

final class AuthorizationDelegationGrantCommand extends Command
{
    public function name(): string
    {
        return 'authz:delegation:grant';
    }

    public function description(): string
    {
        return 'Otorga un role o permission de delegation (trustee puede actuar en nombre del grantor sobre este grant).';
    }

    public function usage(): string
    {
        return 'authz:delegation:grant --trustee-id=... --grantor-id=... [--scope=global] (--role=...|--permission=...) [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:delegation:grant', 'authz:delegate:grant', 'authz:grant-delegation'];
    }

    public function optionsHelp(): array
    {
        return [
            '--trustee-id=' => 'Principal que recibe la delegacion.',
            '--grantor-id=' => 'Principal en nombre del cual podra actuar el trustee.',
            '--scope=' => 'Scope exacto del grant. Default: global.',
            '--role=' => 'Role exacto a delegar.',
            '--permission=' => 'Permission exacta a delegar.',
            '--verbose' => 'Imprime el repositorio administrativo utilizado y el resultado.',
            '--require-published-config' => 'Exige configuracion publicada sin drift antes de aplicar el grant.',
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
            $output->error('Se requiere exactamente una opcion entre --role y --permission (no ambas, no ninguna).');

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
            $granted = $admin->grantDelegation($trusteeId, $grantorId, $grant, $scope);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo otorgar el grant de delegation: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if (! $granted) {
            if ($input->hasOption('verbose')) {
                $output->writeln('El grant de delegation ya existia o los parametros eran invalidos (no-op).');
            }

            return 0;
        }

        $output->writeln(sprintf(
            'Grant de delegation otorgado: trustee=%s grantor=%s scope=%s type=%s value=%s',
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
