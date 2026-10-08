<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Contracts\RelationshipAdministrationInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationRelationshipsListCommand extends Command
{
    public function name(): string
    {
        return 'authz:relationships:list';
    }

    public function description(): string
    {
        return 'Lista relaciones ReBAC operativas desde el repositorio configurado.';
    }

    public function usage(): string
    {
        return 'authz:relationships:list [--principal-id=...] [--relation=...] [--scope=...] [--json]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:relationships:list', 'authz:list-relationships'];
    }

    public function optionsHelp(): array
    {
        return [
            '--principal-id=' => 'Filtra por principal_id exacto.',
            '--relation=' => 'Filtra por relation exacta.',
            '--scope=' => 'Filtra por scope exacto.',
            '--json' => 'Imprime el resultado como JSON en stdout.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $app = $this->bootstrapApplication();
            $admin = $app->make(RelationshipAdministrationInterface::class);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudo resolver la administracion de relaciones: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        $filters = array_filter([
            'principal_id' => $this->stringOption($input, 'principal-id'),
            'relation' => $this->stringOption($input, 'relation'),
            'scope' => $this->stringOption($input, 'scope'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        try {
            $relationships = $admin->listRelationships($filters);
        } catch (\Throwable $exception) {
            $output->error(sprintf(
                'No se pudieron listar las relaciones: %s',
                $exception->getMessage(),
            ));

            return 1;
        }

        if ($input->hasOption('json')) {
            $output->writeln((string) json_encode($relationships, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($relationships === []) {
            $output->writeln('No se encontraron relaciones con los filtros proporcionados.');

            return 0;
        }

        $output->writeln(sprintf('Relaciones encontradas: %d', count($relationships)));

        foreach ($relationships as $index => $relationship) {
            $output->writeln(sprintf(
                '  [%d] principal=%s relation=%s scope=%s resource_key=%s',
                $index + 1,
                $relationship['principal_id'],
                $relationship['relation'],
                $relationship['scope'],
                $relationship['resource_key'],
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
