<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationAuthorityGrantCommand extends Command
{
    public function name(): string
    {
        return 'authz:authority:grant';
    }

    public function description(): string
    {
        return 'Otorga un role o permission exacto al principal indicado.';
    }

    public function usage(): string
    {
        return 'authz:authority:grant --principal-id=... [--scope=global] (--role=...|--permission=...) [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:authority:grant', 'authz:grant-authority'];
    }

    public function optionsHelp(): array
    {
        return [
            '--principal-id=' => 'Principal que recibira el grant.',
            '--scope=' => 'Scope exacto del grant. Default: global.',
            '--role=' => 'Role exacto a otorgar.',
            '--permission=' => 'Permission exacto a otorgar.',
            '--verbose' => 'Imprime el repositorio administrativo utilizado.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de otorgar authority.',
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

        try {
            $created = $type === 'role'
                ? $admin->grantRole($principalId, $value, $scope)
                : $admin->grantPermission($principalId, $value, $scope);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo otorgar el grant de authority: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if ($input->hasOption('verbose')) {
            $output->writeln(sprintf('  Repositorio administrativo: %s', $admin::class));
        }

        if (! $created) {
            $output->writeln('El grant ya existia; no fue necesario modificar el repositorio.');
            $output->writeln(sprintf(
                '  principal=%s scope=%s type=%s value=%s',
                $principalId,
                $scope,
                $type,
                $value,
            ));

            return 0;
        }

        $output->writeln('Grant de authority aplicado correctamente.');
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
