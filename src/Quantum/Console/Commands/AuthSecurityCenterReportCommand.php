<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

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
        return 'auth:security-center:report [--now=timestamp] [--identity=value] [--type=value] [--include-public-ids] [--management-actors] [--correlation-id=value] [--audit-log-source=path] [--export-log=path] [--json] [--verbose]';
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
            '--management-actors' => 'Incluye export operativo de actores con claims administrativas gobernadas.',
            '--correlation-id=' => 'Usa un correlation id explicito para enlazar reporte, export y mutaciones posteriores.',
            '--audit-log-source=' => 'Lee un audit log JSONL de revocaciones para agregar metricas longitudinales.',
            '--export-log=' => 'Anexa un snapshot JSONL durable del reporte operativo generado.',
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
        $includeManagementActors = $input->hasOption('management-actors');
        $auditLogSource = $this->resolveOptionalStringOption($input, 'audit-log-source');
        $exportLogPath = $this->resolveOptionalStringOption($input, 'export-log');
        $json = $input->hasOption('json');
        $generatedAt = $now ?? time();
        $correlationId = $this->resolveCorrelationId($input, 'security-center-report');
        $operationId = $this->createOperationId('security-center-report');
        $operationalContext = $this->operationalContext($app);

        $activeSessions = array_values(array_filter(
            $sessions->all(),
            static fn (AuthenticationSession $session): bool => ! $session->isExpired($now),
        ));
        $activeTrustedDevices = $trustedDevices->all($now);

        $sessionRows = [];
        $managementActors = [];
        $filteredManagementActors = [];
        $identityKeys = [];
        $platformCounts = [];
        $kindCounts = [];

        foreach ($activeSessions as $session) {
            $identifier = $session->reference->identifier->value;
            $identityType = $session->reference->type;
            $identityKeys[strtolower($identityType) . '|' . $identifier] = true;
            $managementActor = $this->managementActorRow($session, $includePublicIds);

            if ($managementActor !== null) {
                $this->mergeManagementActorRow($managementActors, $managementActor);
            }

            if ($identity !== null && ($identifier !== $identity || $identityType !== $type)) {
                continue;
            }

            if ($managementActor !== null) {
                $this->mergeManagementActorRow($filteredManagementActors, $managementActor);
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

        $administrativeMetrics = $this->administrativeMetrics(array_values($managementActors));
        $longitudinalMetrics = $this->longitudinalMetrics($this->readAuditEvents($auditLogSource));

        $payload = [
            'generated_at' => $generatedAt,
            'correlation_id' => $correlationId,
            'operation_id' => $operationId,
            'operational_context' => $operationalContext,
            'filters' => array_filter([
                'identity' => $identity,
                'type' => $identity !== null ? $type : null,
                'include_public_ids' => $includePublicIds,
                'management_actors' => $includeManagementActors ? true : null,
                'audit_log_source' => $auditLogSource,
            ], static fn (mixed $value): bool => $value !== null),
            'summary' => [
                'active_sessions' => count($activeSessions),
                'active_trusted_devices' => count($activeTrustedDevices),
                'unique_identities' => count($identityKeys),
                'aggregated_devices' => count($devices),
                'trusted_aggregates' => $trustedAggregates,
                'elevated_management_aggregates' => $elevatedAggregates,
                'governed_management_sessions' => array_sum(array_map(
                    static fn (array $actor): int => (int) ($actor['session_count'] ?? 0),
                    array_values($managementActors),
                )),
                'governed_management_identities' => count($managementActors),
                'direct_admin_sessions' => array_sum(array_map(
                    static fn (array $actor): int => ($actor['management_authorization_mode'] ?? null) === 'direct_admin'
                        ? (int) ($actor['session_count'] ?? 0)
                        : 0,
                    array_values($managementActors),
                )),
                'delegated_admin_sessions' => array_sum(array_map(
                    static fn (array $actor): int => ($actor['management_authorization_mode'] ?? null) === 'delegated_admin'
                        ? (int) ($actor['session_count'] ?? 0)
                        : 0,
                    array_values($managementActors),
                )),
                'direct_admin_identities' => count(array_filter(
                    $managementActors,
                    static fn (array $actor): bool => ($actor['management_authorization_mode'] ?? null) === 'direct_admin',
                )),
                'delegated_admin_identities' => count(array_filter(
                    $managementActors,
                    static fn (array $actor): bool => ($actor['management_authorization_mode'] ?? null) === 'delegated_admin',
                )),
            ],
            'administrative_metrics' => $administrativeMetrics,
            'longitudinal_metrics' => $longitudinalMetrics,
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

        if ($includeManagementActors) {
            $payload['management_actors'] = array_values($identity !== null ? $filteredManagementActors : $managementActors);
        }

        $this->writeExportEvent($exportLogPath, [
            'event' => 'security_center_report_exported',
            'occurred_at' => $generatedAt,
            'correlation_id' => $correlationId,
            'operation_id' => $operationId,
            'result' => 'exported',
            'report' => $payload,
        ]);

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
        $output->writeln(sprintf('  Sesiones con management gobernado: %d', $payload['summary']['governed_management_sessions']));
        $output->writeln(sprintf('  Identidades con management gobernado: %d', $payload['summary']['governed_management_identities']));
        $output->writeln(sprintf('  Sesiones direct_admin: %d', $payload['summary']['direct_admin_sessions']));
        $output->writeln(sprintf('  Sesiones delegated_admin: %d', $payload['summary']['delegated_admin_sessions']));
        $output->writeln(sprintf('  Correlation id: %s', $correlationId));
        $output->writeln(sprintf('  Operation id: %s', $operationId));
        $output->writeln(sprintf(
            '  Metricas administrativas: authorized=%d unauthorized=%d direct=%d delegated=%d',
            $administrativeMetrics['governed_actor_identities_authorized'],
            $administrativeMetrics['governed_actor_identities_unauthorized'],
            $administrativeMetrics['authorization_modes']['direct_admin'] ?? 0,
            $administrativeMetrics['authorization_modes']['delegated_admin'] ?? 0,
        ));
        if ($auditLogSource !== null) {
            $output->writeln(sprintf(
                '  Metricas longitudinales: events=%d executed=%d dry_run=%d rejected=%d',
                $longitudinalMetrics['audit_event_count'],
                $longitudinalMetrics['outcomes']['executed'] ?? 0,
                $longitudinalMetrics['outcomes']['dry_run'] ?? 0,
                $longitudinalMetrics['outcomes']['authorization_failed'] ?? 0,
            ));
        }

        if ($input->hasOption('verbose')) {
            $output->writeln();
            $output->writeln('Contexto operativo:');
            $output->writeln(sprintf(
                '  app=%s env=%s topology=%s fingerprint=%s',
                $operationalContext['app_name'],
                $operationalContext['app_env'],
                $operationalContext['store_topology'],
                $operationalContext['store_fingerprint'],
            ));
            $output->writeln(sprintf(
                '  session_driver=%s trusted_device_driver=%s',
                $operationalContext['session_driver'],
                $operationalContext['trusted_device_driver'],
            ));

            if ($operationalContext['session_store_path'] !== null || $operationalContext['trusted_device_store_path'] !== null) {
                $output->writeln(sprintf(
                    '  session_store=%s trusted_device_store=%s',
                    $operationalContext['session_store_path'] ?? 'n/a',
                    $operationalContext['trusted_device_store_path'] ?? 'n/a',
                ));
            }
        }

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

        if ($includeManagementActors) {
            $actors = array_values($identity !== null ? $filteredManagementActors : $managementActors);
            $output->writeln();
            $output->writeln('Actores administrativos gobernados:');

            if ($actors === []) {
                $output->writeln('  - none');
            }

            foreach ($actors as $actor) {
                $output->writeln(sprintf(
                    '  - %s:%s | sessions=%d | authority=%s | source=%s | privilege=%s | mode=%s | scopes=%s',
                    $actor['identity_type'],
                    $actor['identity_identifier'],
                    $actor['session_count'],
                    $actor['management_authority'],
                    $actor['management_claims_source'],
                    $actor['management_privilege_level'],
                    $actor['management_authorization_mode'] ?? 'none',
                    implode(',', $actor['management_scopes']),
                ));

                if ($includePublicIds && ($actor['session_public_ids'] ?? []) !== []) {
                    $output->writeln(sprintf(
                        '    session_public_ids=%s',
                        implode(',', $actor['session_public_ids']),
                    ));
                }
            }
        }

        if ($exportLogPath !== null) {
            $output->writeln();
            $output->writeln(sprintf('Snapshot exportado en: %s', $exportLogPath));
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

    private function resolveOptionalStringOption(Input $input, string $key): ?string
    {
        $option = $input->option($key);

        return is_string($option) && trim($option) !== ''
            ? trim($option)
            : null;
    }

    private function resolveCorrelationId(Input $input, string $prefix): string
    {
        $provided = $this->resolveOptionalStringOption($input, 'correlation-id');

        if ($provided !== null) {
            return $provided;
        }

        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    private function createOperationId(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8));
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

    /**
     * @return array<string, mixed>|null
     */
    private function managementActorRow(AuthenticationSession $session, bool $includePublicIds): ?array
    {
        $context = new AuthenticationContext(
            identity: $session->identity,
            reference: $session->reference,
            requestId: 'security-center-report',
            method: $session->method,
            attributes: $session->attributes,
        );

        if (! $context->hasGovernedManagementClaims()) {
            return null;
        }

        $row = [
            'identity_identifier' => $session->reference->identifier->value,
            'identity_type' => $session->reference->type,
            'session_count' => 1,
            'last_seen_at' => $this->timestampAttribute($session->attributes, 'session_last_activity_at') ?? $session->issuedAt,
            'management_authority' => $context->managementAuthority(),
            'management_ownership_proof' => $context->managementOwnershipProof(),
            'management_claims_source' => $context->managementClaimsSource(),
            'management_privilege_level' => $context->managementPrivilegeLevel(),
            'management_authorized' => $context->canAdministrativelyManageDevices(),
            'management_authorization_mode' => $context->managementAuthorizationMode(),
            'management_authorization_reason_code' => $context->managementAuthorizationReasonCode(),
            'management_scopes' => $context->managementScopes(),
        ];

        if ($includePublicIds) {
            $row['session_public_ids'] = array_values(array_filter([
                $session->publicId(),
            ], static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        }

        return $row;
    }

    /**
     * @param array<string, array<string, mixed>> $actors
     * @param array<string, mixed> $row
     */
    private function mergeManagementActorRow(array &$actors, array $row): void
    {
        $key = strtolower((string) $row['identity_type']) . '|' . (string) $row['identity_identifier'];

        if (! isset($actors[$key])) {
            $actors[$key] = $row;
            $actors[$key]['management_scopes'] = array_values(array_unique(array_map(
                'strval',
                (array) ($row['management_scopes'] ?? []),
            )));
            $actors[$key]['session_public_ids'] = array_values(array_unique(array_map(
                'strval',
                (array) ($row['session_public_ids'] ?? []),
            )));

            return;
        }

        $actors[$key]['session_count'] = (int) ($actors[$key]['session_count'] ?? 0) + 1;
        $actors[$key]['last_seen_at'] = max(
            (int) ($actors[$key]['last_seen_at'] ?? 0),
            (int) ($row['last_seen_at'] ?? 0),
        );
        $actors[$key]['management_scopes'] = array_values(array_unique(array_merge(
            array_map('strval', (array) ($actors[$key]['management_scopes'] ?? [])),
            array_map('strval', (array) ($row['management_scopes'] ?? [])),
        )));
        $actors[$key]['session_public_ids'] = array_values(array_unique(array_merge(
            array_map('strval', (array) ($actors[$key]['session_public_ids'] ?? [])),
            array_map('strval', (array) ($row['session_public_ids'] ?? [])),
        )));
    }

    /**
     * @param list<array<string, mixed>> $actors
     * @return array{
     *   governed_actor_sessions_authorized: int,
     *   governed_actor_sessions_unauthorized: int,
     *   governed_actor_identities_authorized: int,
     *   governed_actor_identities_unauthorized: int,
     *   authorization_modes: array<string, int>,
     *   privilege_levels: array<string, int>,
     *   scope_coverage: array<string, int>
     * }
     */
    private function administrativeMetrics(array $actors): array
    {
        $metrics = [
            'governed_actor_sessions_authorized' => 0,
            'governed_actor_sessions_unauthorized' => 0,
            'governed_actor_identities_authorized' => 0,
            'governed_actor_identities_unauthorized' => 0,
            'authorization_modes' => [
                'direct_admin' => 0,
                'delegated_admin' => 0,
                'unauthorized' => 0,
            ],
            'privilege_levels' => [],
            'scope_coverage' => [
                'admin_device_management' => 0,
                'admin_session_management' => 0,
                'admin_trusted_device_management' => 0,
                'security_center_export' => 0,
            ],
        ];

        foreach ($actors as $actor) {
            $sessionCount = (int) ($actor['session_count'] ?? 0);
            $authorized = (bool) ($actor['management_authorized'] ?? false);
            $authorizationMode = $authorized
                ? (string) ($actor['management_authorization_mode'] ?? 'unauthorized')
                : 'unauthorized';
            $privilegeLevel = (string) ($actor['management_privilege_level'] ?? 'unknown');
            $scopes = array_values(array_unique(array_map(
                'strval',
                (array) ($actor['management_scopes'] ?? []),
            )));

            if ($authorized) {
                $metrics['governed_actor_identities_authorized']++;
                $metrics['governed_actor_sessions_authorized'] += $sessionCount;
            } else {
                $metrics['governed_actor_identities_unauthorized']++;
                $metrics['governed_actor_sessions_unauthorized'] += $sessionCount;
            }

            $metrics['authorization_modes'][$authorizationMode] = ($metrics['authorization_modes'][$authorizationMode] ?? 0) + 1;
            $metrics['privilege_levels'][$privilegeLevel] = ($metrics['privilege_levels'][$privilegeLevel] ?? 0) + 1;

            foreach ($scopes as $scope) {
                $metrics['scope_coverage'][$scope] = ($metrics['scope_coverage'][$scope] ?? 0) + 1;
            }
        }

        ksort($metrics['privilege_levels']);
        ksort($metrics['scope_coverage']);

        return $metrics;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{
     *   audit_event_count: int,
     *   unique_correlation_ids: int,
     *   unique_operation_ids: int,
     *   outcomes: array<string, int>,
     *   scopes: array<string, int>,
     *   actor_scope_profiles: array<string, int>,
     *   authorization_modes: array<string, int>,
     *   affected_resources: array<string, int>,
     *   observed_store_fingerprints: int,
     *   observed_topologies: array<string, int>,
     *   latest_event_at: ?int
     * }
     */
    private function longitudinalMetrics(array $events): array
    {
        $metrics = [
            'audit_event_count' => 0,
            'unique_correlation_ids' => 0,
            'unique_operation_ids' => 0,
            'outcomes' => [
                'executed' => 0,
                'dry_run' => 0,
                'authorization_failed' => 0,
                'validation_failed' => 0,
            ],
            'scopes' => [
                'all' => 0,
                'sessions' => 0,
                'trusted-devices' => 0,
            ],
            'actor_scope_profiles' => [
                'full' => 0,
                'sessions_only' => 0,
                'trusted_devices_only' => 0,
                'none' => 0,
            ],
            'authorization_modes' => [
                'direct_admin' => 0,
                'delegated_admin' => 0,
                'none' => 0,
            ],
            'affected_resources' => [
                'sessions' => 0,
                'trusted-devices' => 0,
                'total' => 0,
            ],
            'observed_store_fingerprints' => 0,
            'observed_topologies' => [],
            'latest_event_at' => null,
        ];

        $correlationIds = [];
        $operationIds = [];
        $storeFingerprints = [];

        foreach ($events as $event) {
            $metrics['audit_event_count']++;

            $correlationId = $event['correlation_id'] ?? null;
            if (is_string($correlationId) && trim($correlationId) !== '') {
                $correlationIds[trim($correlationId)] = true;
            }

            $operationId = $event['operation_id'] ?? null;
            if (is_string($operationId) && trim($operationId) !== '') {
                $operationIds[trim($operationId)] = true;
            }

            $occurredAt = $event['occurred_at'] ?? null;
            if (is_numeric($occurredAt)) {
                $occurredAt = (int) $occurredAt;
                $metrics['latest_event_at'] = max((int) ($metrics['latest_event_at'] ?? 0), $occurredAt);
            }

            $outcome = $this->normalizedMetricKey($event['result'] ?? null, 'unknown');
            $metrics['outcomes'][$outcome] = ($metrics['outcomes'][$outcome] ?? 0) + 1;

            $administrativeMetrics = is_array($event['administrative_metrics'] ?? null)
                ? $event['administrative_metrics']
                : [];
            $requestedScope = $this->normalizedMetricKey($administrativeMetrics['requested_scope'] ?? null, 'all');
            $actorScopeProfile = $this->normalizedMetricKey($administrativeMetrics['actor_scope_profile'] ?? null, 'none');
            $authorizationMode = $this->normalizedMetricKey($administrativeMetrics['actor_authorization_mode'] ?? null, 'none');
            $affectedTotalResources = $administrativeMetrics['affected_total_resources'] ?? 0;
            $eventSummary = is_array($event['summary'] ?? null)
                ? $event['summary']
                : [];

            $metrics['scopes'][$requestedScope] = ($metrics['scopes'][$requestedScope] ?? 0) + 1;
            $metrics['actor_scope_profiles'][$actorScopeProfile] = ($metrics['actor_scope_profiles'][$actorScopeProfile] ?? 0) + 1;
            $metrics['authorization_modes'][$authorizationMode] = ($metrics['authorization_modes'][$authorizationMode] ?? 0) + 1;
            $metrics['affected_resources']['total'] += is_numeric($affectedTotalResources) ? (int) $affectedTotalResources : 0;
            $metrics['affected_resources']['sessions'] += is_numeric($eventSummary['revoked_sessions'] ?? null)
                ? (int) $eventSummary['revoked_sessions']
                : 0;
            $metrics['affected_resources']['trusted-devices'] += is_numeric($eventSummary['revoked_trusted_devices'] ?? null)
                ? (int) $eventSummary['revoked_trusted_devices']
                : 0;

            $operationalContext = is_array($event['operational_context'] ?? null)
                ? $event['operational_context']
                : [];
            $fingerprint = $operationalContext['store_fingerprint'] ?? null;
            if (is_string($fingerprint) && trim($fingerprint) !== '') {
                $storeFingerprints[trim($fingerprint)] = true;
            }

            $topology = $this->normalizedMetricKey($operationalContext['store_topology'] ?? null, 'unknown');
            $metrics['observed_topologies'][$topology] = ($metrics['observed_topologies'][$topology] ?? 0) + 1;
        }

        $metrics['unique_correlation_ids'] = count($correlationIds);
        $metrics['unique_operation_ids'] = count($operationIds);
        $metrics['observed_store_fingerprints'] = count($storeFingerprints);
        ksort($metrics['observed_topologies']);

        return $metrics;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readAuditEvents(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($lines)) {
            return [];
        }

        $events = [];

        foreach ($lines as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

            $eventName = $decoded['event'] ?? null;
            if (! is_string($eventName) || ! str_starts_with($eventName, 'security_center_device_revocation_')) {
                continue;
            }

            $events[] = $decoded;
        }

        return $events;
    }

    private function normalizedMetricKey(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $default;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function writeExportEvent(?string $path, array $event): void
    {
        if ($path === null) {
            return;
        }

        $directory = dirname($path);

        if ($directory !== '' && $directory !== '.' && ! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $path,
            (string) json_encode($event, JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND,
        );
    }

    /**
     * @return array{
     *   app_name: string,
     *   app_env: string,
     *   session_driver: string,
     *   trusted_device_driver: string,
     *   session_store_path: ?string,
     *   trusted_device_store_path: ?string,
     *   store_topology: string,
     *   store_fingerprint: string
     * }
     */
    private function operationalContext(Application $app): array
    {
        $sessionDriver = $this->normalizedDriver($app->config('auth.session.driver', 'memory'), 'memory');
        $trustedDeviceDriver = $this->normalizedDriver(
            $app->config('auth.trusted_devices.driver', $sessionDriver),
            $sessionDriver,
        );
        $sessionStorePath = $sessionDriver === 'file'
            ? $app->storagePath('framework/auth/sessions')
            : null;
        $trustedDeviceStorePath = $trustedDeviceDriver === 'file'
            ? $app->storagePath('framework/auth/trusted-devices')
            : null;
        $topology = match (true) {
            $sessionDriver === 'file' && $trustedDeviceDriver === 'file' => 'shared_file_store_candidate',
            $sessionDriver === 'file' || $trustedDeviceDriver === 'file' => 'mixed_driver_topology',
            default => 'in_memory_local_topology',
        };

        return [
            'app_name' => $this->normalizedString($app->config('app.name', 'VoltStack'), 'VoltStack'),
            'app_env' => $this->normalizedString($app->config('app.env', 'local'), 'local'),
            'session_driver' => $sessionDriver,
            'trusted_device_driver' => $trustedDeviceDriver,
            'session_store_path' => $sessionStorePath,
            'trusted_device_store_path' => $trustedDeviceStorePath,
            'store_topology' => $topology,
            'store_fingerprint' => sha1((string) json_encode([
                'session_driver' => $sessionDriver,
                'trusted_device_driver' => $trustedDeviceDriver,
                'session_store_path' => $sessionStorePath,
                'trusted_device_store_path' => $trustedDeviceStorePath,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    private function normalizedDriver(mixed $value, string $default): string
    {
        return $this->normalizedString($value, $default);
    }

    private function normalizedString(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $default;
    }
}
