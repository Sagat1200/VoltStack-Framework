<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Contracts\RelationshipAdministrationInterface;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationRelationshipsRevokeCommand extends Command
{
    public function name(): string
    {
        return 'authz:relationships:revoke';
    }

    public function description(): string
    {
        return 'Revoca una relacion ReBAC exacta desde el repositorio configurado.';
    }

    public function usage(): string
    {
        return 'authz:relationships:revoke --principal-id=... --relation=... --resource-key=... [--scope=global] [--dry-run] [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:relationships:revoke', 'authz:revoke-relationship'];
    }

    public function optionsHelp(): array
    {
        return [
            '--principal-id=' => 'Principal propietario de la relacion.',
            '--relation=' => 'Nombre exacto de la relacion.',
            '--resource-key=' => 'Clave persistida del recurso (ej. string:doc-1 o App\\Doc:42).',
            '--scope=' => 'Scope exacto de la relacion. Default: global.',
            '--dry-run' => 'Muestra la operacion sin modificar el repositorio.',
            '--verbose' => 'Imprime el repositorio administrativo utilizado.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de revocar relaciones.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $principalId = $this->requiredStringOption($input, 'principal-id');
        $relation = $this->requiredStringOption($input, 'relation');
        $resourceKey = $this->requiredStringOption($input, 'resource-key');
        $scope = $this->stringOption($input, 'scope') ?? 'global';

        if ($principalId === null || $relation === null || $resourceKey === null) {
            $output->error('Faltan opciones requeridas: --principal-id, --relation y --resource-key.');

            return 1;
        }

        try {
            $app = $this->bootstrapApplication(requirePublishedConfig: $input->hasOption('require-published-config'));
        } catch (PublishedConfigurationRequiredException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la administracion de relaciones: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        try {
            $admin = $app->make(RelationshipAdministrationInterface::class);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la administracion de relaciones: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if ($input->hasOption('dry-run')) {
            $output->writeln('[dry-run] La relacion seria revocada.');
            $output->writeln(sprintf(
                '  principal=%s relation=%s scope=%s resource_key=%s',
                $principalId,
                $relation,
                $scope,
                $resourceKey,
            ));

            return 0;
        }

        try {
            $revoked = $admin->revokeRelationshipByKey($principalId, $relation, $resourceKey, $scope);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo revocar la relacion: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if ($input->hasOption('verbose')) {
            $output->writeln(sprintf('  Repositorio administrativo: %s', $admin::class));
        }

        if (! $revoked) {
            $output->writeln('No se encontro una relacion coincidente para revocar.');

            return 0;
        }

        $output->writeln('Relacion revocada correctamente.');
        $output->writeln(sprintf(
            '  principal=%s relation=%s scope=%s resource_key=%s',
            $principalId,
            $relation,
            $scope,
            $resourceKey,
        ));

        return 0;
    }

    private function requiredStringOption(Input $input, string $name): ?string
    {
        return $this->stringOption($input, $name);
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
