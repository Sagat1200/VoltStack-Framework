<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthSecurityCenterReportCommand extends Command
{
    public function name(): string
    {
        return 'auth:security-center:report';
    }

    public function description(): string
    {
        return 'Genera un reporte operativo del security center sobre sesiones, trusted devices e inventario agregado.';
    }

    public function usage(): string
    {
        return 'auth:security-center:report [--now=timestamp] [--identity=value] [--type=value] [--include-public-ids] [--json] [--verbose]';
    }

    public function category(): string
    {
        return 'Authentication';
    }

    public function optionsHelp(): array
    {
        return [
            '--now=' => 'Usa un timestamp UNIX especifico para evaluar expiracion.',
            '--identity=' => 'Filtra el reporte detallado a un identifier concreto.',
            '--type=' => 'Filtra por tipo de identidad. Default: user.',
            '--include-public-ids' => 'Incluye session_public_ids y trusted_device_public_id en el detalle.',
            '--json' => 'Emite el reporte en JSON.',
            '--verbose' => 'Muestra distribuciones adicionales y metadatos del reporte.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);
        $now = $this->resolveNow($input);
        $identity = $this->resolveIdentityFilter($input);
        $type = $this->resolveTypeFilter($input);
        $includePublicIds = $input->hasOption('include-public-ids');
        $json = $input->hasOption('json');

        $activeSessions = array_values(array_filter(
            $sessions->all(),
            static fn (AuthenticationSession $session): bool => ! $session->isExpired($now),
        ));
        $activeTrustedDevices = $trustedDevices->all($now);

        $sessionRows = [];
        $identityKeys = [];
        $platformCounts = [];
        $kindCounts = [];

        foreach ($activeSessions as $session) {
            $identifier = $session->reference->identifier->value;
            $identityType = $session->reference->type;
            $identityKeys[strtolower($identityType) . '|' . $identifier] = true;

            if ($identity !== null && ($identifier !== $identity || $identityType !== $type)) {
                continue;
            }

            $platform = $this->stringAttribute($session->attributes, 'session_client_platform');
            $deviceKind = $this->stringAttribute($session->attributes, 'session_device_kind');

            if ($platform !== null) {
                $platformCounts[$platform] = ($platformCounts[$platform] ?? 0) + 1;
            }

            if ($deviceKind !== null) {
                $kindCounts[$deviceKind] = ($kindCounts[$deviceKind] ?? 0) + 1;
            }

            $sessionRows[] = [
                'identity_identifier' => $identifier,
                'identity_type' => $identityType,
                'device_reference' => $this->stringAttribute($session->attributes, 'session_device_reference'),
                'session_public_id' => $session->publicId(),
                'trust_state' => $this->stringAttribute($session->attributes, 'session_device_trust_state') ?? 'unknown',
                'trusted_device_public_id' => $this->stringAttribute($session->attributes, 'trusted_device_public_id'),
                'trusted_device_credential_present' => (bool) ($session->attributes['trusted_device_credential_present'] ?? false),
                'client_platform' => $platform,
                'device_kind' => $deviceKind,
                'label' => $session->label(),
                'last_seen_at' => $this->timestampAttribute($session->attributes, 'session_last_activity_at') ?? $session->issuedAt,
            ];
        }

        $trustedRows = [];

        foreach ($activeTrustedDevices as $device) {
            $identifier = $device->reference->identifier->value;
            $identityType = $device->reference->type;
            $identityKeys[strtolower($identityType) . '|' . $identifier] = true;

            if ($identity !== null && ($identifier !== $identity || $identityType !== $type)) {
                continue;
            }

            $trustedRows[] = [
                'identity_identifier' => $identifier,
                'identity_type' => $identityType,
                'device_reference' => $device->deviceReference,
                'trusted_device_public_id' => $device->publicId->value,
                'client_platform' => $this->stringAttribute($device->attributes, 'client_platform'),
                'device_kind' => $this->stringAttribute($device->attributes, 'device_kind'),
                'label' => $this->stringAttribute($device->attributes, 'label'),
                'last_seen_at' => $device->lastUsedAt ?? $device->issuedAt,
            ];
        }

        $devices = $this->aggregateDevices($sessionRows, $trustedRows, $includePublicIds);
        usort($devices, static fn (array $left, array $right): int => ($right['last_seen_at'] ?? 0) <=> ($left['last_seen_at'] ?? 0));

        $trustedAggregates = 0;
        $elevatedAggregates = 0;

        foreach ($devices as $device) {
            if (($device['trust_state'] ?? 'unknown') === 'trusted') {
                $trustedAggregates++;
            }

            if (($device['management_sensitivity'] ?? 'standard') === 'elevated') {
                $elevatedAggregates++;
            }
        }

        $payload = [
            'generated_at' => $now ?? time(),
            'filters' => array_filter([
                'identity' => $identity,
                'type' => $identity !== null ? $type : null,
                'include_public_ids' => $includePublicIds,
            ], static fn (mixed $value): bool => $value !== null),
            'summary' => [
                'active_sessions' => count($activeSessions),
                'active_trusted_devices' => count($activeTrustedDevices),
                'unique_identities' => count($identityKeys),
                'aggregated_devices' => count($devices),
                'trusted_aggregates' => $trustedAggregates,
                'elevated_management_aggregates' => $elevatedAggregates,
            ],
        ];

        if ($input->hasOption('verbose')) {
            arsort($platformCounts);
            arsort($kindCounts);
            $payload['distribution'] = [
                'client_platforms' => $platformCounts,
                'device_kinds' => $kindCounts,
            ];
        }

        if ($identity !== null) {
            $payload['devices'] = $devices;
        }

        if ($json) {
            $output->writeln((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        }

        $output->writeln('Reporte de security center generado correctamente.');
        $output->writeln(sprintf('  Sesiones activas: %d', $payload['summary']['active_sessions']));
        $output->writeln(sprintf('  Trusted devices activos: %d', $payload['summary']['active_trusted_devices']));
        $output->writeln(sprintf('  Identidades unicas: %d', $payload['summary']['unique_identities']));
        $output->writeln(sprintf('  Dispositivos agregados: %d', $payload['summary']['aggregated_devices']));
        $output->writeln(sprintf('  Agregados trusted: %d', $payload['summary']['trusted_aggregates']));
        $output->writeln(sprintf('  Agregados de management elevado: %d', $payload['summary']['elevated_management_aggregates']));

        if ($identity !== null) {
            $output->writeln();
            $output->writeln(sprintf('Detalle para %s:%s', $type, $identity));

            foreach ($devices as $device) {
                $output->writeln(sprintf(
                    '  - %s | sessions=%d | trusted=%s | authority=%s | proof=%s | sensitivity=%s | platform=%s | kind=%s',
                    $device['device_reference'],
                    $device['session_count'],
                    $device['has_trusted_device'] ? 'si' : 'no',
                    $device['management_authority'],
                    $device['management_ownership_proof'],
                    $device['management_sensitivity'],
                    $device['client_platform'] ?? 'n/a',
                    $device['device_kind'] ?? 'n/a',
                ));

                if ($includePublicIds) {
                    $output->writeln(sprintf(
                        '    session_public_ids=%s trusted_device_public_id=%s',
                        implode(',', $device['session_public_ids']),
                        $device['trusted_device_public_id'] ?? 'null',
                    ));
                }
            }
        } elseif ($includePublicIds) {
            $output->writeln();
            $output->writeln('Nota: --include-public-ids solo expone detalle cuando se filtra una identidad concreta.');
        }

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

    private function resolveIdentityFilter(Input $input): ?string
    {
        $option = $input->option('identity');

        return is_string($option) && trim($option) !== ''
            ? trim($option)
            : null;
    }

    private function resolveTypeFilter(Input $input): string
    {
        $option = $input->option('type');

        return is_string($option) && trim($option) !== ''
            ? trim($option)
            : 'user';
    }

    /**
     * @param list<array{
     *   identity_identifier: string,
     *   identity_type: string,
     *   device_reference: ?string,
     *   session_public_id: ?string,
     *   trust_state: string,
     *   trusted_device_public_id: ?string,
     *   trusted_device_credential_present: bool,
     *   client_platform: ?string,
     *   device_kind: ?string,
     *   label: ?string,
     *   last_seen_at: int
     * }> $sessionRows
     * @param list<array{
     *   identity_identifier: string,
     *   identity_type: string,
     *   device_reference: string,
     *   trusted_device_public_id: string,
     *   client_platform: ?string,
     *   device_kind: ?string,
     *   label: ?string,
     *   last_seen_at: int
     * }> $trustedRows
     * @return list<array<string, mixed>>
     */
    private function aggregateDevices(array $sessionRows, array $trustedRows, bool $includePublicIds): array
    {
        $devices = [];

        foreach ($sessionRows as $session) {
            $deviceReference = $session['device_reference'];

            if (! is_string($deviceReference) || trim($deviceReference) === '') {
                continue;
            }

            $key = strtolower($session['identity_type']) . '|' . $session['identity_identifier'] . '|' . trim($deviceReference);

            if (! isset($devices[$key])) {
                $devices[$key] = [
                    'identity_identifier' => $session['identity_identifier'],
                    'identity_type' => $session['identity_type'],
                    'device_reference' => trim($deviceReference),
                    'trust_state' => $session['trust_state'],
                    'session_count' => 0,
                    'has_trusted_device' => false,
                    'management_authority' => 'identity_owner',
                    'management_ownership_proof' => 'identity_session',
                    'trusted_device_public_id' => null,
                    'session_public_ids' => [],
                    'last_seen_at' => $session['last_seen_at'],
                    'label' => $session['label'],
                    'client_platform' => $session['client_platform'],
                    'device_kind' => $session['device_kind'],
                ];
            }

            $devices[$key]['session_count']++;
            $devices[$key]['last_seen_at'] = max((int) $devices[$key]['last_seen_at'], $session['last_seen_at']);
            $devices[$key]['label'] = $devices[$key]['label'] ?? $session['label'];
            $devices[$key]['client_platform'] = $devices[$key]['client_platform'] ?? $session['client_platform'];
            $devices[$key]['device_kind'] = $devices[$key]['device_kind'] ?? $session['device_kind'];

            if ($session['trust_state'] === 'trusted') {
                $devices[$key]['trust_state'] = 'trusted';
            }

            if ($includePublicIds && is_string($session['session_public_id']) && trim($session['session_public_id']) !== '') {
                $devices[$key]['session_public_ids'][] = trim($session['session_public_id']);
            }
        }

        foreach ($trustedRows as $trusted) {
            $key = strtolower($trusted['identity_type']) . '|' . $trusted['identity_identifier'] . '|' . $trusted['device_reference'];

            if (! isset($devices[$key])) {
                $devices[$key] = [
                    'identity_identifier' => $trusted['identity_identifier'],
                    'identity_type' => $trusted['identity_type'],
                    'device_reference' => $trusted['device_reference'],
                    'trust_state' => 'trusted',
                    'session_count' => 0,
                    'has_trusted_device' => true,
                    'management_authority' => 'identity_owner',
                    'management_ownership_proof' => 'identity_session',
                    'trusted_device_public_id' => $includePublicIds ? $trusted['trusted_device_public_id'] : null,
                    'session_public_ids' => [],
                    'last_seen_at' => $trusted['last_seen_at'],
                    'label' => $trusted['label'],
                    'client_platform' => $trusted['client_platform'],
                    'device_kind' => $trusted['device_kind'],
                ];
            } else {
                $devices[$key]['has_trusted_device'] = true;
                $devices[$key]['trust_state'] = 'trusted';
                $devices[$key]['last_seen_at'] = max((int) $devices[$key]['last_seen_at'], $trusted['last_seen_at']);
                $devices[$key]['label'] = $devices[$key]['label'] ?? $trusted['label'];
                $devices[$key]['client_platform'] = $devices[$key]['client_platform'] ?? $trusted['client_platform'];
                $devices[$key]['device_kind'] = $devices[$key]['device_kind'] ?? $trusted['device_kind'];
            }

            if ($includePublicIds) {
                $devices[$key]['trusted_device_public_id'] = $trusted['trusted_device_public_id'];
            }
        }

        foreach ($devices as &$device) {
            $device['management_sensitivity'] = ($device['session_count'] > 1 || $device['has_trusted_device'])
                ? 'elevated'
                : 'standard';
            $device['management_reason_code'] = $device['has_trusted_device']
                ? 'trusted_device_management'
                : (($device['session_count'] > 1)
                    ? 'remote_device_management'
                    : 'current_device_management');
        }
        unset($device);

        return array_values($devices);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function stringAttribute(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function timestampAttribute(array $attributes, string $key): ?int
    {
        $value = $attributes[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
