<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthSessionsCleanupCommand extends Command
{
    public function name(): string
    {
        return 'auth:sessions:cleanup';
    }

    public function description(): string
    {
        return 'Purga sesiones expiradas, trusted devices vencidos y tombstones de recovery del subsistema Authentication.';
    }

    public function usage(): string
    {
        return 'auth:sessions:cleanup [--now=timestamp] [--verbose]';
    }

    public function category(): string
    {
        return 'Authentication';
    }

    public function optionsHelp(): array
    {
        return [
            '--now=' => 'Usa un timestamp UNIX especifico para la evaluacion de expiracion y retencion.',
            '--verbose' => 'Muestra detalle del driver activo y del retention window configurado.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);
        $now = $this->resolveNow($input);
        $expired = $repository->purgeExpired($now);
        $expiredTrustedDevices = $trustedDevices->purgeExpired($now);
        $tombstones = $repository->purgeRecoveryReasons($now);

        if ($input->hasOption('verbose')) {
            $driver = (string) $app->config('auth.session.driver', 'memory');
            $trustedDeviceDriver = $app->config('auth.trusted_devices.driver', $driver);
            $trustedDeviceDriver = is_string($trustedDeviceDriver) && trim($trustedDeviceDriver) !== ''
                ? trim($trustedDeviceDriver)
                : $driver;
            $retention = $app->config('auth.session.cleanup.tombstone_retention', 604800);
            $retention = is_numeric($retention) ? (int) $retention : 604800;

            $output->writeln(sprintf('Driver activo: %s', $driver));
            $output->writeln(sprintf('Driver trusted devices: %s', $trustedDeviceDriver));
            $output->writeln(sprintf('Retention tombstones: %d segundos', max(0, $retention)));
            $output->writeln(sprintf('Evaluado en: %d', $now ?? time()));
            $output->writeln();
        }

        $output->writeln('Cleanup de Authentication ejecutado correctamente.');
        $output->writeln(sprintf('  Sesiones expiradas purgadas: %d', $expired));
        $output->writeln(sprintf('  Trusted devices expirados purgados: %d', $expiredTrustedDevices));
        $output->writeln(sprintf('  Tombstones purgados: %d', $tombstones));

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
