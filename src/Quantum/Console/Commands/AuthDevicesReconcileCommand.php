<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Sessions\AuthenticationSession;
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
        return 'auth:devices:reconcile [--now=timestamp] [--dry-run] [--verbose]';
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
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);
        $now = $this->resolveNow($input);
        $dryRun = $input->hasOption('dry-run');

        $trustedIndex = [];

        foreach ($trustedDevices->all($now) as $device) {
            $trustedIndex[$this->trustedDeviceKey(
                $device->reference->type,
                $device->reference->identifier->value,
                $device->deviceReference,
            )] = $device->publicId->value;
        }

        $scanned = 0;
        $updated = 0;
        $promoted = 0;
        $demoted = 0;
        $skippedExpired = 0;
        $skippedWithoutDevice = 0;

        foreach ($sessions->all() as $session) {
            if ($session->isExpired($now)) {
                $skippedExpired++;
                continue;
            }

            $scanned++;
            $deviceReference = $this->sessionDeviceReference($session);

            if ($deviceReference === null) {
                $skippedWithoutDevice++;
                continue;
            }

            $trustedDevicePublicId = $trustedIndex[$this->trustedDeviceKey(
                $session->reference->type,
                $session->reference->identifier->value,
                $deviceReference,
            )] ?? null;

            $expectedTrusted = $trustedDevicePublicId !== null;
            $currentTrusted = $this->sessionTrustState($session) === 'trusted';
            $currentTrustedDevicePublicId = $this->sessionTrustedDevicePublicId($session);
            $currentCredentialPresent = (bool) ($session->attributes['trusted_device_credential_present'] ?? false);

            if (
                $currentTrusted === $expectedTrusted
                && $currentTrustedDevicePublicId === $trustedDevicePublicId
                && $currentCredentialPresent === $expectedTrusted
            ) {
                continue;
            }

            $updated++;
            $promoted += $expectedTrusted ? 1 : 0;
            $demoted += $expectedTrusted ? 0 : 1;

            if ($dryRun) {
                continue;
            }

            $sessions->touch(new AuthenticationSession(
                id: $session->id,
                identity: $session->identity,
                reference: $session->reference,
                method: $session->method,
                issuedAt: $session->issuedAt,
                expiresAt: $session->expiresAt,
                attributes: array_merge($session->attributes, [
                    'session_device_trust_state' => $expectedTrusted ? 'trusted' : 'unknown',
                    'trusted_device_public_id' => $trustedDevicePublicId,
                    'trusted_device_credential_present' => $expectedTrusted,
                ]),
            ));
        }

        if ($input->hasOption('verbose')) {
            $sessionDriver = (string) $app->config('auth.session.driver', 'memory');
            $trustedDriver = $app->config('auth.trusted_devices.driver', $sessionDriver);
            $trustedDriver = is_string($trustedDriver) && trim($trustedDriver) !== ''
                ? trim($trustedDriver)
                : $sessionDriver;

            $output->writeln(sprintf('Driver sesiones: %s', $sessionDriver));
            $output->writeln(sprintf('Driver trusted devices: %s', $trustedDriver));
            $output->writeln(sprintf('Evaluado en: %d', $now ?? time()));
            $output->writeln(sprintf('Dry run: %s', $dryRun ? 'si' : 'no'));
            $output->writeln();
        }

        $output->writeln($dryRun
            ? 'Reconciliacion de trusted devices calculada correctamente (dry-run).'
            : 'Reconciliacion de trusted devices ejecutada correctamente.');
        $output->writeln(sprintf('  Sesiones activas escaneadas: %d', $scanned));
        $output->writeln(sprintf('  Sesiones reconciliadas: %d', $updated));
        $output->writeln(sprintf('  Promovidas a trusted: %d', $promoted));
        $output->writeln(sprintf('  Degradadas a unknown: %d', $demoted));
        $output->writeln(sprintf('  Sesiones expiradas omitidas: %d', $skippedExpired));
        $output->writeln(sprintf('  Sesiones sin device_reference omitidas: %d', $skippedWithoutDevice));

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

    private function sessionDeviceReference(AuthenticationSession $session): ?string
    {
        $value = $session->attributes['session_device_reference'] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function sessionTrustState(AuthenticationSession $session): string
    {
        $value = $session->attributes['session_device_trust_state'] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : 'unknown';
    }

    private function sessionTrustedDevicePublicId(AuthenticationSession $session): ?string
    {
        $value = $session->attributes['trusted_device_public_id'] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function trustedDeviceKey(string $type, string $identifier, string $deviceReference): string
    {
        return strtolower(trim($type)) . '|' . trim($identifier) . '|' . trim($deviceReference);
    }
}
