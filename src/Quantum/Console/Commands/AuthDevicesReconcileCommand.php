<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Auth\Contracts\InventoryReconcilerInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthDevicesReconcileCommand extends Command
{
    public function name(): string
    {
        return 'auth:devices:reconcile';
    }

    public function description(): string
    {
        return 'Reconcilia el estado trusted/untrusted de las sesiones frente al store compartido de trusted devices.';
    }

    public function usage(): string
    {
        return 'auth:devices:reconcile [--now=timestamp] [--dry-run] [--verbose] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Authentication';
    }

    public function optionsHelp(): array
    {
        return [
            '--now=' => 'Usa un timestamp UNIX especifico para evaluar expiracion durante la reconciliacion.',
            '--dry-run' => 'Calcula y reporta cambios sin persistir modificaciones.',
            '--verbose' => 'Muestra detalle adicional del driver y del resultado por categoria.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de reconciliar trusted devices.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $now = $this->resolveNow($input);
        $dryRun = $input->hasOption('dry-run');
        [$sessionDriver, $trustedDriver, $result] = $this->runInCommandRuntime(function ($app) use ($now, $dryRun) {
            $reconciler = $app->make(InventoryReconcilerInterface::class);
            $result = $reconciler->reconcile($now, $dryRun);
            $sessionDriver = (string) $app->config('auth.session.driver', 'memory');
            $trustedDriver = $app->config('auth.trusted_devices.driver', $sessionDriver);
            $trustedDriver = is_string($trustedDriver) && trim($trustedDriver) !== ''
                ? trim($trustedDriver)
                : $sessionDriver;

            return [$sessionDriver, $trustedDriver, $result];
        }, requirePublishedConfig: $input->hasOption('require-published-config'));

        if ($input->hasOption('verbose')) {
            $output->writeln(sprintf('Driver sesiones: %s', $sessionDriver));
            $output->writeln(sprintf('Driver trusted devices: %s', $trustedDriver));
            $output->writeln(sprintf('Evaluado en: %d', $result['evaluated_at']));
            $output->writeln(sprintf('Dry run: %s', $dryRun ? 'si' : 'no'));
            $output->writeln();
        }

        $output->writeln($dryRun
            ? 'Reconciliacion de trusted devices calculada correctamente (dry-run).'
            : 'Reconciliacion de trusted devices ejecutada correctamente.');
        $output->writeln(sprintf('  Sesiones activas escaneadas: %d', $result['scanned']));
        $output->writeln(sprintf('  Sesiones reconciliadas: %d', $result['updated']));
        $output->writeln(sprintf('  Promovidas a trusted: %d', $result['promoted']));
        $output->writeln(sprintf('  Degradadas a unknown: %d', $result['demoted']));
        $output->writeln(sprintf('  Sesiones expiradas omitidas: %d', $result['skipped_expired']));
        $output->writeln(sprintf('  Sesiones sin device_reference omitidas: %d', $result['skipped_without_device']));

        return 0;
    }

    private function resolveNow(Input $input): ?int
    {
        $option = $input->option('now');

        if (is_string($option) && trim($option) !== '' && is_numeric($option)) {
            return (int) $option;
        }

        return null;
    }
}
