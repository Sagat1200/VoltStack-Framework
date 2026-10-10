<?php

declare(strict_types=1);

namespace Quantum\Authorization\Console\Commands;

use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthorizationManifestClearCommand extends Command
{
    public function name(): string
    {
        return 'authz:manifest:clear';
    }

    public function description(): string
    {
        return 'Limpia todas las entries del manifest de autorizacion persistido.';
    }

    public function usage(): string
    {
        return 'authz:manifest:clear [--verbose] [--dry-run] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authorization';
    }

    public function aliases(): array
    {
        return ['authorization:manifest:clear', 'authz:clear-manifest'];
    }

    public function optionsHelp(): array
    {
        return [
            '--verbose' => 'Muestra el detalle de entries eliminadas cuando el store filesystem esta activo.',
            '--dry-run' => 'Calcula las entries que se eliminarian pero no modifica el store fisicamente.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de limpiar el manifest.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication(requirePublishedConfig: $input->hasOption('require-published-config'));
        $store = $app->make(AuthorizationManifestStoreInterface::class);
        $verbose = $input->hasOption('verbose');
        $dryRun = $input->hasOption('dry-run');

        $count = 0;

        if (! $dryRun) {
            try {
                $count = $store->clear();
            } catch (\Throwable $exception) {
                $output->error(sprintf(
                    'No se pudo limpiar el manifest de autorizacion: %s',
                    $exception->getMessage(),
                ));

                return 1;
            }
        }

        if ($count === 0 && ! $dryRun) {
            $output->writeln('No habia entries en el manifest de autorizacion para eliminar.');

            return 0;
        }

        if ($dryRun) {
            $output->writeln('[dry-run] El comando authz:manifest:clear eliminaria el contenido del manifest store.');
            $output->writeln(sprintf('  Store activo: %s', $store::class));

            return 0;
        }

        if ($verbose) {
            $output->writeln(sprintf('  Store utilizado: %s', $store::class));
        }

        $output->writeln('Manifest de autorizacion limpiado correctamente.');
        $output->writeln(sprintf('  Entries eliminadas: %d', $count));

        return 0;
    }
}
