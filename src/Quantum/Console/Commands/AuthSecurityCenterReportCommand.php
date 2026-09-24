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
    /**
     * @return array{
     *   evaluated: bool,
     *   audit_log_source: ?string,
     *   activity_drift: array<string, mixed>,
     *   operational_response: array<string, mixed>
     * }
     */
    public function distributedGuardFromAuditLog(?string $auditLogSource): array
    {
        $metrics = $this->longitudinalMetrics($this->readAuditEvents($auditLogSource));
        $activityDrift = is_array($metrics['activity_drift'] ?? null)
            ? $metrics['activity_drift']
            : [];

        return [
            'evaluated' => $auditLogSource !== null,
            'audit_log_source' => $auditLogSource,
            'activity_drift' => $activityDrift,
            'operational_response' => is_array($activityDrift['operational_response'] ?? null)
                ? $activityDrift['operational_response']
                : [],
        ];
    }

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
            $topStoreCohort = $longitudinalMetrics['store_cohorts'][0] ?? null;
            $timeWindows = $longitudinalMetrics['time_windows'] ?? [];
            $storeTimeWindows = $longitudinalMetrics['store_time_windows'] ?? [];
            $multiStoreSummary = is_array($longitudinalMetrics['multi_store_summary'] ?? null)
                ? $longitudinalMetrics['multi_store_summary']
                : [];
            $activityDrift = is_array($longitudinalMetrics['activity_drift'] ?? null)
                ? $longitudinalMetrics['activity_drift']
                : [];
            $last5m = $timeWindows[0] ?? null;
            $last15m = $timeWindows[1] ?? null;
            $last60m = $timeWindows[2] ?? null;
            $topRecentStore = $storeTimeWindows[0] ?? null;

            if (is_array($topStoreCohort)) {
                $output->writeln(sprintf(
                    '  Cohortes distribuidas: stores=%d top_store=%s events=%d topology=%s',
                    $longitudinalMetrics['observed_store_fingerprints'],
                    $topStoreCohort['store_fingerprint'],
                    $topStoreCohort['event_count'],
                    $topStoreCohort['store_topology'],
                ));
            }

            if (is_array($last5m) && is_array($last15m) && is_array($last60m)) {
                $output->writeln(sprintf(
                    '  Ventanas distribuidas: 5m=%d 15m=%d 60m=%d',
                    $last5m['event_count'],
                    $last15m['event_count'],
                    $last60m['event_count'],
                ));
            }

            if (is_array($topRecentStore)) {
                $recent15m = $topRecentStore['windows'][1]['event_count'] ?? 0;
                $recent60m = $topRecentStore['windows'][2]['event_count'] ?? 0;
                $output->writeln(sprintf(
                    '  Consolidacion temporal por store: stores=%d top_recent_store=%s 15m=%d 60m=%d',
                    count($storeTimeWindows),
                    $topRecentStore['store_fingerprint'],
                    $recent15m,
                    $recent60m,
                ));
            }

            if ($multiStoreSummary !== []) {
                $output->writeln(sprintf(
                    '  Resumen multi-store: profile=%s active_15m=%d/%d spread=%ss',
                    $multiStoreSummary['coordination_profile'] ?? 'idle',
                    $multiStoreSummary['active_stores_last_15m'] ?? 0,
                    $multiStoreSummary['observed_stores'] ?? 0,
                    $multiStoreSummary['latest_event_spread_seconds'] ?? 0,
                ));
            }

            if ($activityDrift !== []) {
                $operationalResponse = is_array($activityDrift['operational_response'] ?? null)
                    ? $activityDrift['operational_response']
                    : [];
                $output->writeln(sprintf(
                    '  Activity drift: detected=%s profile=%s lagging=%d inactive_15m=%d gap=%ss action=%s response=%s deny_remote=%s',
                    ($activityDrift['drift_detected'] ?? false) ? 'yes' : 'no',
                    $activityDrift['drift_profile'] ?? 'none',
                    count((array) ($activityDrift['lagging_store_fingerprints'] ?? [])),
                    $activityDrift['inactive_stores_last_15m'] ?? 0,
                    $activityDrift['max_event_gap_seconds'] ?? 0,
                    $activityDrift['recommended_action'] ?? 'none',
                    $operationalResponse['response_mode'] ?? 'normal_operations',
                    ($operationalResponse['should_deny_remote_mutations'] ?? false) ? 'yes' : 'no',
                ));
            }
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

            if ($auditLogSource !== null && $longitudinalMetrics['store_cohorts'] !== []) {
                $output->writeln();
                $output->writeln('Cohortes distribuidas por store:');

                foreach ($longitudinalMetrics['store_cohorts'] as $cohort) {
                    $output->writeln(sprintf(
                        '  - fingerprint=%s | topology=%s | events=%d | executed=%d | dry_run=%d | rejected=%d',
                        $cohort['store_fingerprint'],
                        $cohort['store_topology'],
                        $cohort['event_count'],
                        $cohort['outcomes']['executed'] ?? 0,
                        $cohort['outcomes']['dry_run'] ?? 0,
                        $cohort['outcomes']['authorization_failed'] ?? 0,
                    ));
                }
            }

            if ($auditLogSource !== null && ($longitudinalMetrics['time_windows'] ?? []) !== []) {
                $output->writeln();
                $output->writeln('Ventanas temporales distribuidas:');

                foreach ($longitudinalMetrics['time_windows'] as $window) {
                    $output->writeln(sprintf(
                        '  - %s | events=%d | stores=%d | top_store=%s | executed=%d | dry_run=%d | rejected=%d',
                        $window['label'],
                        $window['event_count'],
                        $window['observed_store_fingerprints'],
                        $window['top_store_fingerprint'] ?? 'none',
                        $window['outcomes']['executed'] ?? 0,
                        $window['outcomes']['dry_run'] ?? 0,
                        $window['outcomes']['authorization_failed'] ?? 0,
                    ));
                }
            }

            if ($auditLogSource !== null && ($longitudinalMetrics['store_time_windows'] ?? []) !== []) {
                $output->writeln();
                $output->writeln('Consolidacion temporal por store:');

                foreach ($longitudinalMetrics['store_time_windows'] as $storeWindow) {
                    $window5m = $storeWindow['windows'][0]['event_count'] ?? 0;
                    $window15m = $storeWindow['windows'][1]['event_count'] ?? 0;
                    $window60m = $storeWindow['windows'][2]['event_count'] ?? 0;

                    $output->writeln(sprintf(
                        '  - fingerprint=%s | topology=%s | 5m=%d | 15m=%d | 60m=%d | latest=%s',
                        $storeWindow['store_fingerprint'],
                        $storeWindow['store_topology'],
                        $window5m,
                        $window15m,
                        $window60m,
                        $storeWindow['latest_event_at'] ?? 'null',
                    ));
                }
            }

            if ($auditLogSource !== null && ($longitudinalMetrics['multi_store_summary'] ?? []) !== []) {
                $multiStoreSummary = $longitudinalMetrics['multi_store_summary'];
                $output->writeln();
                $output->writeln('Resumen multi-store:');
                $output->writeln(sprintf(
                    '  - profile=%s | stores=%d | active_5m=%d | active_15m=%d | active_60m=%d | top_store=%s | spread=%ss',
                    $multiStoreSummary['coordination_profile'] ?? 'idle',
                    $multiStoreSummary['observed_stores'] ?? 0,
                    $multiStoreSummary['active_stores_last_5m'] ?? 0,
                    $multiStoreSummary['active_stores_last_15m'] ?? 0,
                    $multiStoreSummary['active_stores_last_60m'] ?? 0,
                    $multiStoreSummary['top_recent_store_fingerprint'] ?? 'none',
                    $multiStoreSummary['latest_event_spread_seconds'] ?? 0,
                ));
            }

            if ($auditLogSource !== null && ($longitudinalMetrics['activity_drift'] ?? []) !== []) {
                $activityDrift = $longitudinalMetrics['activity_drift'];
                $operationalResponse = is_array($activityDrift['operational_response'] ?? null)
                    ? $activityDrift['operational_response']
                    : [];
                $output->writeln();
                $output->writeln('Activity drift:');
                $output->writeln(sprintf(
                    '  - detected=%s | profile=%s | severity=%s | lagging=%d | inactive_15m=%d | inactive_60m=%d | gap=%ss | action=%s | reference_store=%s | response=%s',
                    ($activityDrift['drift_detected'] ?? false) ? 'yes' : 'no',
                    $activityDrift['drift_profile'] ?? 'none',
                    $activityDrift['severity'] ?? 'none',
                    count((array) ($activityDrift['lagging_store_fingerprints'] ?? [])),
                    $activityDrift['inactive_stores_last_15m'] ?? 0,
                    $activityDrift['inactive_stores_last_60m'] ?? 0,
                    $activityDrift['max_event_gap_seconds'] ?? 0,
                    $activityDrift['recommended_action'] ?? 'none',
                    $activityDrift['reference_store_fingerprint'] ?? 'none',
                    $operationalResponse['response_mode'] ?? 'normal_operations',
                ));

                if ($operationalResponse !== []) {
                    $output->writeln('  Respuesta operativa:');
                    $output->writeln(sprintf(
                        '    - escalation=%s | deny_remote=%s | denial_reason=%s | next_step=%s | scope_policy=%s | allow=%s | deny=%s | targets=%d',
                        $operationalResponse['escalation_level'] ?? 'none',
                        ($operationalResponse['should_deny_remote_mutations'] ?? false) ? 'yes' : 'no',
                        $operationalResponse['remote_mutation_denial_reason_code'] ?? 'none',
                        $operationalResponse['next_step'] ?? 'continue_normal_operations',
                        $operationalResponse['remote_mutation_scope_policy'] ?? 'allow_all',
                        implode(',', (array) ($operationalResponse['allowed_remote_mutation_scopes'] ?? [])),
                        implode(',', (array) ($operationalResponse['denied_remote_mutation_scopes'] ?? [])),
                        count((array) ($operationalResponse['target_store_fingerprints'] ?? [])),
                    ));

                    if (($operationalResponse['degraded_scope_profiles'] ?? []) !== []) {
                        $output->writeln(sprintf(
                            '    - degraded_profiles=%s',
                            implode(',', (array) ($operationalResponse['degraded_scope_profiles'] ?? [])),
                        ));
                    }

                    if (($operationalResponse['mutation_scope_profiles'] ?? []) !== []) {
                        $output->writeln(sprintf(
                            '    - mutation_profiles=%s',
                            implode(',', array_map(
                                static fn (array $profile): string => sprintf(
                                    '%s:%s',
                                    (string) ($profile['mutation_kind'] ?? 'unknown'),
                                    (bool) ($profile['should_deny'] ?? false) ? 'deny' : 'allow',
                                ),
                                array_filter(
                                    (array) ($operationalResponse['mutation_scope_profiles'] ?? []),
                                    static fn (mixed $value): bool => is_array($value),
                                ),
                            )),
                        ));
                    }

                    if (($operationalResponse['target_store_assessments'] ?? []) !== []) {
                        $output->writeln(sprintf(
                            '    - target_store_assessments=%s',
                            implode(',', array_map(
                                static fn (array $assessment): string => sprintf(
                                    '%s:%s',
                                    (string) ($assessment['store_fingerprint'] ?? 'unknown-store'),
                                    (string) ($assessment['status'] ?? 'unknown'),
                                ),
                                array_filter(
                                    (array) ($operationalResponse['target_store_assessments'] ?? []),
                                    static fn (mixed $value): bool => is_array($value),
                                ),
                            )),
                        ));
                    }
                }

                if (($activityDrift['window_coverage'] ?? []) !== []) {
                    $output->writeln('  Cobertura por ventana:');

                    foreach ((array) $activityDrift['window_coverage'] as $windowCoverage) {
                        $output->writeln(sprintf(
                            '    - %s | active=%d/%d | inactive=%d',
                            $windowCoverage['label'] ?? 'unknown',
                            $windowCoverage['active_stores'] ?? 0,
                            $windowCoverage['observed_stores'] ?? 0,
                            $windowCoverage['inactive_stores'] ?? 0,
                        ));
                    }
                }

                if (($activityDrift['store_assessments'] ?? []) !== []) {
                    $output->writeln('  Drift por store:');

                    foreach ((array) $activityDrift['store_assessments'] as $storeAssessment) {
                        $output->writeln(sprintf(
                            '    - fingerprint=%s | topology=%s | status=%s | gap=%ss | 5m=%d | 15m=%d | 60m=%d',
                            $storeAssessment['store_fingerprint'] ?? 'unknown-store',
                            $storeAssessment['store_topology'] ?? 'unknown',
                            $storeAssessment['status'] ?? 'unknown',
                            $storeAssessment['event_gap_seconds'] ?? 0,
                            $storeAssessment['events_last_5m'] ?? 0,
                            $storeAssessment['events_last_15m'] ?? 0,
                            $storeAssessment['events_last_60m'] ?? 0,
                        ));
                    }
                }
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
     *   mutation_kinds: array<string, int>,
     *   distributed_guard_policy_sources: array<string, int>,
     *   distributed_guard_policy_reason_codes: array<string, int>,
     *   distributed_guard_reason_codes: array<string, int>,
     *   observed_store_fingerprints: int,
     *   observed_topologies: array<string, int>,
     *   latest_event_at: ?int,
     *   store_cohorts: list<array<string, mixed>>,
     *   time_windows: list<array<string, mixed>>,
     *   store_time_windows: list<array<string, mixed>>,
     *   multi_store_summary: array<string, mixed>,
     *   activity_drift: array<string, mixed>
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
            'mutation_kinds' => [],
            'distributed_guard_policy_sources' => [],
            'distributed_guard_policy_reason_codes' => [],
            'distributed_guard_reason_codes' => [],
            'observed_store_fingerprints' => 0,
            'observed_topologies' => [],
            'latest_event_at' => null,
            'store_cohorts' => [],
            'time_windows' => [],
            'store_time_windows' => [],
            'multi_store_summary' => [],
            'activity_drift' => [],
        ];

        $correlationIds = [];
        $operationIds = [];
        $storeFingerprints = [];
        $storeCohorts = [];
        $normalizedEvents = [];

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

            $distributedGuardScopeDecision = is_array($event['distributed_guard_scope_decision'] ?? null)
                ? $event['distributed_guard_scope_decision']
                : [];
            $mutationKind = $this->normalizedMetricKey(
                $distributedGuardScopeDecision['mutation_kind'] ?? null,
                match ($requestedScope) {
                    'sessions' => 'session_revocation',
                    'trusted-devices' => 'trusted_device_revocation',
                    default => 'aggregated_device_revocation',
                },
            );
            $metrics['mutation_kinds'][$mutationKind] = ($metrics['mutation_kinds'][$mutationKind] ?? 0) + 1;

            $policySource = $distributedGuardScopeDecision['policy_source'] ?? null;
            if (is_string($policySource) && trim($policySource) !== '') {
                $normalizedPolicySource = trim($policySource);
                $metrics['distributed_guard_policy_sources'][$normalizedPolicySource] = ($metrics['distributed_guard_policy_sources'][$normalizedPolicySource] ?? 0) + 1;
            }

            $policyReasonCode = $distributedGuardScopeDecision['policy_reason_code'] ?? null;
            if (is_string($policyReasonCode) && trim($policyReasonCode) !== '') {
                $normalizedPolicyReasonCode = trim($policyReasonCode);
                $metrics['distributed_guard_policy_reason_codes'][$normalizedPolicyReasonCode] = ($metrics['distributed_guard_policy_reason_codes'][$normalizedPolicyReasonCode] ?? 0) + 1;
            }

            $reasonCode = $distributedGuardScopeDecision['reason_code'] ?? ($event['reason_code'] ?? null);
            if (is_string($reasonCode) && trim($reasonCode) !== '') {
                $normalizedReasonCode = trim($reasonCode);
                $metrics['distributed_guard_reason_codes'][$normalizedReasonCode] = ($metrics['distributed_guard_reason_codes'][$normalizedReasonCode] ?? 0) + 1;
            }

            $operationalContext = is_array($event['operational_context'] ?? null)
                ? $event['operational_context']
                : [];
            $fingerprint = $operationalContext['store_fingerprint'] ?? null;
            if (is_string($fingerprint) && trim($fingerprint) !== '') {
                $normalizedFingerprint = trim($fingerprint);
                $storeFingerprints[$normalizedFingerprint] = true;
            } else {
                $normalizedFingerprint = 'unknown-store';
            }

            $topology = $this->normalizedMetricKey($operationalContext['store_topology'] ?? null, 'unknown');
            $metrics['observed_topologies'][$topology] = ($metrics['observed_topologies'][$topology] ?? 0) + 1;
            $cohortKey = $topology . '|' . $normalizedFingerprint;

            if (! isset($storeCohorts[$cohortKey])) {
                $storeCohorts[$cohortKey] = [
                    'store_fingerprint' => $normalizedFingerprint,
                    'store_topology' => $topology,
                    'event_count' => 0,
                    'unique_correlation_ids' => [],
                    'unique_operation_ids' => [],
                    'outcomes' => [],
                    'scopes' => [],
                    'authorization_modes' => [],
                    'mutation_kinds' => [],
                    'distributed_guard_policy_sources' => [],
                    'distributed_guard_policy_reason_codes' => [],
                    'distributed_guard_reason_codes' => [],
                    'affected_resources' => [
                        'sessions' => 0,
                        'trusted-devices' => 0,
                        'total' => 0,
                    ],
                    'latest_event_at' => null,
                ];
            }

            $storeCohorts[$cohortKey]['event_count']++;
            if (is_string($correlationId) && trim($correlationId) !== '') {
                $storeCohorts[$cohortKey]['unique_correlation_ids'][trim($correlationId)] = true;
            }
            if (is_string($operationId) && trim($operationId) !== '') {
                $storeCohorts[$cohortKey]['unique_operation_ids'][trim($operationId)] = true;
            }
            $storeCohorts[$cohortKey]['outcomes'][$outcome] = ($storeCohorts[$cohortKey]['outcomes'][$outcome] ?? 0) + 1;
            $storeCohorts[$cohortKey]['scopes'][$requestedScope] = ($storeCohorts[$cohortKey]['scopes'][$requestedScope] ?? 0) + 1;
            $storeCohorts[$cohortKey]['authorization_modes'][$authorizationMode] = ($storeCohorts[$cohortKey]['authorization_modes'][$authorizationMode] ?? 0) + 1;
            $storeCohorts[$cohortKey]['mutation_kinds'][$mutationKind] = ($storeCohorts[$cohortKey]['mutation_kinds'][$mutationKind] ?? 0) + 1;
            $storeCohorts[$cohortKey]['affected_resources']['total'] += is_numeric($affectedTotalResources) ? (int) $affectedTotalResources : 0;
            $storeCohorts[$cohortKey]['affected_resources']['sessions'] += is_numeric($eventSummary['revoked_sessions'] ?? null)
                ? (int) $eventSummary['revoked_sessions']
                : 0;
            $storeCohorts[$cohortKey]['affected_resources']['trusted-devices'] += is_numeric($eventSummary['revoked_trusted_devices'] ?? null)
                ? (int) $eventSummary['revoked_trusted_devices']
                : 0;
            if (is_string($policySource) && trim($policySource) !== '') {
                $storeCohorts[$cohortKey]['distributed_guard_policy_sources'][$normalizedPolicySource] = ($storeCohorts[$cohortKey]['distributed_guard_policy_sources'][$normalizedPolicySource] ?? 0) + 1;
            }
            if (is_string($policyReasonCode) && trim($policyReasonCode) !== '') {
                $storeCohorts[$cohortKey]['distributed_guard_policy_reason_codes'][$normalizedPolicyReasonCode] = ($storeCohorts[$cohortKey]['distributed_guard_policy_reason_codes'][$normalizedPolicyReasonCode] ?? 0) + 1;
            }
            if (is_string($reasonCode) && trim($reasonCode) !== '') {
                $storeCohorts[$cohortKey]['distributed_guard_reason_codes'][$normalizedReasonCode] = ($storeCohorts[$cohortKey]['distributed_guard_reason_codes'][$normalizedReasonCode] ?? 0) + 1;
            }

            if (is_numeric($occurredAt ?? null)) {
                $storeCohorts[$cohortKey]['latest_event_at'] = max(
                    (int) ($storeCohorts[$cohortKey]['latest_event_at'] ?? 0),
                    (int) $occurredAt,
                );
            }

            $normalizedEvents[] = [
                'occurred_at' => is_numeric($occurredAt ?? null) ? (int) $occurredAt : null,
                'store_fingerprint' => $normalizedFingerprint,
                'store_topology' => $topology,
                'outcome' => $outcome,
                'scope' => $requestedScope,
                'authorization_mode' => $authorizationMode,
                'affected_sessions' => is_numeric($eventSummary['revoked_sessions'] ?? null)
                    ? (int) $eventSummary['revoked_sessions']
                    : 0,
                'affected_trusted_devices' => is_numeric($eventSummary['revoked_trusted_devices'] ?? null)
                    ? (int) $eventSummary['revoked_trusted_devices']
                    : 0,
                'affected_total_resources' => is_numeric($affectedTotalResources)
                    ? (int) $affectedTotalResources
                    : 0,
            ];
        }

        $metrics['unique_correlation_ids'] = count($correlationIds);
        $metrics['unique_operation_ids'] = count($operationIds);
        $metrics['observed_store_fingerprints'] = count($storeFingerprints);
        ksort($metrics['observed_topologies']);
        $metrics['store_cohorts'] = $this->normalizeStoreCohorts($storeCohorts);
        $metrics['time_windows'] = $this->buildTimeWindows(
            $normalizedEvents,
            is_int($metrics['latest_event_at']) ? $metrics['latest_event_at'] : null,
        );
        $metrics['store_time_windows'] = $this->buildStoreTimeWindows(
            $normalizedEvents,
            is_int($metrics['latest_event_at']) ? $metrics['latest_event_at'] : null,
        );
        $metrics['multi_store_summary'] = $this->buildMultiStoreSummary(
            $metrics['store_time_windows'],
            is_int($metrics['latest_event_at']) ? $metrics['latest_event_at'] : null,
        );
        $metrics['activity_drift'] = $this->buildActivityDrift(
            $metrics['store_time_windows'],
            $metrics['multi_store_summary'],
            is_int($metrics['latest_event_at']) ? $metrics['latest_event_at'] : null,
        );

        return $metrics;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function buildTimeWindows(array $events, ?int $anchorTimestamp): array
    {
        if ($anchorTimestamp === null) {
            return [];
        }

        $windows = [
            ['label' => 'last_5m', 'duration_seconds' => 300],
            ['label' => 'last_15m', 'duration_seconds' => 900],
            ['label' => 'last_60m', 'duration_seconds' => 3600],
        ];

        $normalized = [];

        foreach ($windows as $window) {
            $windowStart = $anchorTimestamp - $window['duration_seconds'];
            $matchingEvents = array_values(array_filter(
                $events,
                static fn (array $event): bool => is_int($event['occurred_at'] ?? null)
                    && $event['occurred_at'] > $windowStart
                    && $event['occurred_at'] <= $anchorTimestamp,
            ));
            $storeFingerprints = [];
            $outcomes = [];
            $scopes = [];
            $authorizationModes = [];
            $storeCounts = [];
            $affectedResources = [
                'sessions' => 0,
                'trusted-devices' => 0,
                'total' => 0,
            ];

            foreach ($matchingEvents as $event) {
                $fingerprint = is_string($event['store_fingerprint'] ?? null)
                    ? $event['store_fingerprint']
                    : 'unknown-store';
                $outcome = $this->normalizedMetricKey($event['outcome'] ?? null, 'unknown');
                $scope = $this->normalizedMetricKey($event['scope'] ?? null, 'all');
                $authorizationMode = $this->normalizedMetricKey($event['authorization_mode'] ?? null, 'none');

                $storeFingerprints[$fingerprint] = true;
                $storeCounts[$fingerprint] = ($storeCounts[$fingerprint] ?? 0) + 1;
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
                $scopes[$scope] = ($scopes[$scope] ?? 0) + 1;
                $authorizationModes[$authorizationMode] = ($authorizationModes[$authorizationMode] ?? 0) + 1;
                $affectedResources['sessions'] += (int) ($event['affected_sessions'] ?? 0);
                $affectedResources['trusted-devices'] += (int) ($event['affected_trusted_devices'] ?? 0);
                $affectedResources['total'] += (int) ($event['affected_total_resources'] ?? 0);
            }

            arsort($storeCounts);
            ksort($outcomes);
            ksort($scopes);
            ksort($authorizationModes);

            $normalized[] = [
                'label' => $window['label'],
                'duration_seconds' => $window['duration_seconds'],
                'window_start_at' => $windowStart + 1,
                'window_end_at' => $anchorTimestamp,
                'event_count' => count($matchingEvents),
                'observed_store_fingerprints' => count($storeFingerprints),
                'top_store_fingerprint' => array_key_first($storeCounts),
                'outcomes' => $outcomes,
                'scopes' => $scopes,
                'authorization_modes' => $authorizationModes,
                'affected_resources' => $affectedResources,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function buildStoreTimeWindows(array $events, ?int $anchorTimestamp): array
    {
        if ($anchorTimestamp === null) {
            return [];
        }

        $windows = [
            ['label' => 'last_5m', 'duration_seconds' => 300],
            ['label' => 'last_15m', 'duration_seconds' => 900],
            ['label' => 'last_60m', 'duration_seconds' => 3600],
        ];

        $stores = [];

        foreach ($events as $event) {
            $fingerprint = is_string($event['store_fingerprint'] ?? null)
                ? $event['store_fingerprint']
                : 'unknown-store';
            $topology = $this->normalizedMetricKey($event['store_topology'] ?? null, 'unknown');
            $key = $topology . '|' . $fingerprint;

            if (! isset($stores[$key])) {
                $stores[$key] = [
                    'store_fingerprint' => $fingerprint,
                    'store_topology' => $topology,
                    'latest_event_at' => null,
                    'windows' => [],
                ];
            }

            if (is_int($event['occurred_at'] ?? null)) {
                $stores[$key]['latest_event_at'] = max(
                    (int) ($stores[$key]['latest_event_at'] ?? 0),
                    (int) $event['occurred_at'],
                );
            }
        }

        foreach ($stores as $key => $store) {
            foreach ($windows as $window) {
                $windowStart = $anchorTimestamp - $window['duration_seconds'];
                $matchingEvents = array_values(array_filter(
                    $events,
                    static fn (array $event): bool => ($event['store_fingerprint'] ?? 'unknown-store') === $store['store_fingerprint']
                        && ($event['store_topology'] ?? 'unknown') === $store['store_topology']
                        && is_int($event['occurred_at'] ?? null)
                        && $event['occurred_at'] > $windowStart
                        && $event['occurred_at'] <= $anchorTimestamp,
                ));
                $outcomes = [];
                $affectedResources = [
                    'sessions' => 0,
                    'trusted-devices' => 0,
                    'total' => 0,
                ];

                foreach ($matchingEvents as $event) {
                    $outcome = $this->normalizedMetricKey($event['outcome'] ?? null, 'unknown');
                    $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
                    $affectedResources['sessions'] += (int) ($event['affected_sessions'] ?? 0);
                    $affectedResources['trusted-devices'] += (int) ($event['affected_trusted_devices'] ?? 0);
                    $affectedResources['total'] += (int) ($event['affected_total_resources'] ?? 0);
                }

                ksort($outcomes);

                $stores[$key]['windows'][] = [
                    'label' => $window['label'],
                    'duration_seconds' => $window['duration_seconds'],
                    'event_count' => count($matchingEvents),
                    'outcomes' => $outcomes,
                    'affected_resources' => $affectedResources,
                ];
            }
        }

        $normalized = array_values($stores);

        usort($normalized, static function (array $left, array $right): int {
            $leftRecent = (int) ($left['windows'][1]['event_count'] ?? 0);
            $rightRecent = (int) ($right['windows'][1]['event_count'] ?? 0);
            $recentComparison = $rightRecent <=> $leftRecent;

            if ($recentComparison !== 0) {
                return $recentComparison;
            }

            $leftTotal = (int) ($left['windows'][2]['event_count'] ?? 0);
            $rightTotal = (int) ($right['windows'][2]['event_count'] ?? 0);
            $totalComparison = $rightTotal <=> $leftTotal;

            if ($totalComparison !== 0) {
                return $totalComparison;
            }

            return strcmp((string) ($left['store_fingerprint'] ?? ''), (string) ($right['store_fingerprint'] ?? ''));
        });

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $storeTimeWindows
     * @return array<string, mixed>
     */
    private function buildMultiStoreSummary(array $storeTimeWindows, ?int $anchorTimestamp): array
    {
        $summary = [
            'observed_stores' => count($storeTimeWindows),
            'active_stores_last_5m' => 0,
            'active_stores_last_15m' => 0,
            'active_stores_last_60m' => 0,
            'top_recent_store_fingerprint' => null,
            'top_recent_store_topology' => null,
            'top_recent_store_15m_events' => 0,
            'top_recent_store_60m_events' => 0,
            'top_recent_store_share_15m' => 0.0,
            'latest_event_spread_seconds' => null,
            'coordination_profile' => 'idle',
        ];

        if ($storeTimeWindows === []) {
            return $summary;
        }

        $latestEventAts = [];

        foreach ($storeTimeWindows as $storeWindow) {
            $window5m = (int) ($storeWindow['windows'][0]['event_count'] ?? 0);
            $window15m = (int) ($storeWindow['windows'][1]['event_count'] ?? 0);
            $window60m = (int) ($storeWindow['windows'][2]['event_count'] ?? 0);

            if ($window5m > 0) {
                $summary['active_stores_last_5m']++;
            }
            if ($window15m > 0) {
                $summary['active_stores_last_15m']++;
            }
            if ($window60m > 0) {
                $summary['active_stores_last_60m']++;
            }

            if (
                $summary['top_recent_store_fingerprint'] === null
                || $window15m > $summary['top_recent_store_15m_events']
                || (
                    $window15m === $summary['top_recent_store_15m_events']
                    && $window60m > $summary['top_recent_store_60m_events']
                )
            ) {
                $summary['top_recent_store_fingerprint'] = $storeWindow['store_fingerprint'] ?? null;
                $summary['top_recent_store_topology'] = $storeWindow['store_topology'] ?? null;
                $summary['top_recent_store_15m_events'] = $window15m;
                $summary['top_recent_store_60m_events'] = $window60m;
            }

            if (is_int($storeWindow['latest_event_at'] ?? null) && $window60m > 0) {
                $latestEventAts[] = (int) $storeWindow['latest_event_at'];
            }
        }

        if ($latestEventAts !== []) {
            sort($latestEventAts);
            $summary['latest_event_spread_seconds'] = max($latestEventAts) - min($latestEventAts);
        }

        $total15mEvents = array_sum(array_map(
            static fn (array $storeWindow): int => (int) ($storeWindow['windows'][1]['event_count'] ?? 0),
            $storeTimeWindows,
        ));
        $topShare = $total15mEvents > 0
            ? ((int) $summary['top_recent_store_15m_events']) / $total15mEvents
            : 0.0;
        $summary['top_recent_store_share_15m'] = $topShare;

        $summary['coordination_profile'] = match (true) {
            $summary['observed_stores'] <= 1 => 'single_store',
            $summary['active_stores_last_15m'] === 0 => 'idle',
            $summary['active_stores_last_15m'] === 1 => 'concentrated',
            $topShare >= 0.75 => 'concentrated',
            default => 'distributed',
        };

        if ($anchorTimestamp !== null && $summary['latest_event_spread_seconds'] === null) {
            $summary['latest_event_spread_seconds'] = 0;
        }

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $storeTimeWindows
     * @param array<string, mixed> $multiStoreSummary
     * @return array<string, mixed>
     */
    private function buildActivityDrift(array $storeTimeWindows, array $multiStoreSummary, ?int $anchorTimestamp): array
    {
        $drift = [
            'drift_detected' => false,
            'drift_profile' => 'none',
            'coordination_profile' => (string) ($multiStoreSummary['coordination_profile'] ?? 'idle'),
            'severity' => 'none',
            'reference_window' => 'last_15m',
            'recommended_action' => 'none',
            'reference_store_fingerprint' => null,
            'reference_store_topology' => null,
            'max_event_gap_seconds' => null,
            'inactive_stores_last_15m' => 0,
            'inactive_stores_last_60m' => 0,
            'lagging_store_fingerprints' => [],
            'stale_store_fingerprints' => [],
            'window_coverage' => [],
            'store_assessments' => [],
            'operational_response' => [
                'response_mode' => 'normal_operations',
                'escalation_level' => 'none',
                'should_deny_remote_mutations' => false,
                'remote_mutation_denial_reason_code' => 'none',
                'remote_mutation_scope_policy' => 'allow_all',
                'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                'denied_remote_mutation_scopes' => [],
                'scope_denial_reason_codes' => [],
                'degraded_scope_profiles' => [],
                'next_step' => 'continue_normal_operations',
                'target_store_fingerprints' => [],
            ],
        ];

        if ($storeTimeWindows === [] || $anchorTimestamp === null) {
            return $drift;
        }

        $laggingStores = [];
        $staleStores = [];
        $inactive15m = 0;
        $inactive60m = 0;
        $maxGap = 0;
        $lagThresholdSeconds = 600;
        $staleThresholdSeconds = 3600;
        $storeAssessments = [];

        foreach ($storeTimeWindows as $storeWindow) {
            $fingerprint = (string) ($storeWindow['store_fingerprint'] ?? 'unknown-store');
            $topology = (string) ($storeWindow['store_topology'] ?? 'unknown');
            $window5m = (int) ($storeWindow['windows'][0]['event_count'] ?? 0);
            $window15m = (int) ($storeWindow['windows'][1]['event_count'] ?? 0);
            $window60m = (int) ($storeWindow['windows'][2]['event_count'] ?? 0);
            $latestEventAt = is_int($storeWindow['latest_event_at'] ?? null)
                ? (int) $storeWindow['latest_event_at']
                : null;

            if ($window15m === 0) {
                $inactive15m++;
            }

            if ($window60m === 0) {
                $inactive60m++;
            }

            if ($latestEventAt === null) {
                continue;
            }

            $gap = max(0, $anchorTimestamp - $latestEventAt);
            $maxGap = max($maxGap, $gap);

            if ($window60m > 0 && $gap >= $lagThresholdSeconds) {
                $laggingStores[] = $fingerprint;
            }

            if ($gap >= $staleThresholdSeconds || $window60m === 0) {
                $staleStores[] = $fingerprint;
            }

            $storeAssessments[] = [
                'store_fingerprint' => $fingerprint,
                'store_topology' => $topology,
                'latest_event_at' => $latestEventAt,
                'event_gap_seconds' => $gap,
                'events_last_5m' => $window5m,
                'events_last_15m' => $window15m,
                'events_last_60m' => $window60m,
                'status' => match (true) {
                    $gap >= $staleThresholdSeconds || $window60m === 0 => 'stale',
                    $window15m === 0 => 'inactive_15m',
                    $gap >= $lagThresholdSeconds => 'lagging',
                    default => 'healthy',
                },
            ];
        }

        sort($laggingStores);
        sort($staleStores);
        usort($storeAssessments, static function (array $left, array $right): int {
            $leftGap = (int) ($left['event_gap_seconds'] ?? 0);
            $rightGap = (int) ($right['event_gap_seconds'] ?? 0);
            $gapComparison = $rightGap <=> $leftGap;

            if ($gapComparison !== 0) {
                return $gapComparison;
            }

            return strcmp((string) ($left['store_fingerprint'] ?? ''), (string) ($right['store_fingerprint'] ?? ''));
        });

        $drift['max_event_gap_seconds'] = $maxGap;
        $drift['inactive_stores_last_15m'] = $inactive15m;
        $drift['inactive_stores_last_60m'] = $inactive60m;
        $drift['lagging_store_fingerprints'] = $laggingStores;
        $drift['stale_store_fingerprints'] = $staleStores;
        $drift['reference_store_fingerprint'] = $multiStoreSummary['top_recent_store_fingerprint'] ?? null;
        $drift['reference_store_topology'] = $multiStoreSummary['top_recent_store_topology'] ?? null;
        $drift['window_coverage'] = [
            [
                'label' => 'last_5m',
                'active_stores' => (int) ($multiStoreSummary['active_stores_last_5m'] ?? 0),
                'inactive_stores' => max(0, count($storeTimeWindows) - (int) ($multiStoreSummary['active_stores_last_5m'] ?? 0)),
                'observed_stores' => count($storeTimeWindows),
            ],
            [
                'label' => 'last_15m',
                'active_stores' => (int) ($multiStoreSummary['active_stores_last_15m'] ?? 0),
                'inactive_stores' => max(0, count($storeTimeWindows) - (int) ($multiStoreSummary['active_stores_last_15m'] ?? 0)),
                'observed_stores' => count($storeTimeWindows),
            ],
            [
                'label' => 'last_60m',
                'active_stores' => (int) ($multiStoreSummary['active_stores_last_60m'] ?? 0),
                'inactive_stores' => max(0, count($storeTimeWindows) - (int) ($multiStoreSummary['active_stores_last_60m'] ?? 0)),
                'observed_stores' => count($storeTimeWindows),
            ],
        ];
        $drift['store_assessments'] = $storeAssessments;
        $concentratedActivity = $inactive15m === 0
            && $inactive60m === 0
            && $laggingStores === []
            && ($multiStoreSummary['coordination_profile'] ?? null) === 'concentrated';
        $drift['drift_detected'] = $inactive15m > 0 || $inactive60m > 0 || $laggingStores !== [] || $concentratedActivity;

        $drift['drift_profile'] = match (true) {
            $inactive60m > 0 => 'store_dropout',
            $inactive15m > 0 => 'partial_visibility',
            $laggingStores !== [] => 'recent_lag',
            $concentratedActivity => 'concentrated_activity',
            default => 'none',
        };

        $drift['severity'] = match (true) {
            $drift['drift_detected'] === false => 'none',
            $inactive60m > 0 || $maxGap >= 3600 => 'high',
            $inactive15m > 0 || $maxGap >= 1800 => 'medium',
            $concentratedActivity => 'low',
            default => 'low',
        };

        $drift['recommended_action'] = match (true) {
            $drift['drift_detected'] === false && ($multiStoreSummary['coordination_profile'] ?? null) === 'single_store' => 'single_store_baseline',
            $drift['drift_detected'] === false => 'none',
            $inactive60m > 0 => 'investigate_store_dropout',
            $inactive15m > 0 => 'rebalance_partial_visibility',
            $laggingStores !== [] => 'monitor_recent_lag',
            $concentratedActivity => 'monitor_concentrated_activity',
            default => 'none',
        };

        if (($multiStoreSummary['coordination_profile'] ?? null) === 'single_store' && $drift['drift_detected'] === false) {
            $drift['drift_profile'] = 'single_store';
        }

        $drift['operational_response'] = $this->buildDriftOperationalResponse($drift);

        return $drift;
    }

    /**
     * @param array<string, mixed> $drift
     * @return array<string, mixed>
     */
    private function buildDriftOperationalResponse(array $drift): array
    {
        $recommendedAction = (string) ($drift['recommended_action'] ?? 'none');
        $severity = (string) ($drift['severity'] ?? 'none');
        $targetStores = array_values(array_filter(array_unique(array_merge(
            array_map(
                static fn (mixed $value): string => (string) $value,
                (array) ($drift['stale_store_fingerprints'] ?? []),
            ),
            array_map(
                static fn (mixed $value): string => (string) $value,
                (array) ($drift['lagging_store_fingerprints'] ?? []),
            ),
        ))));

        if ($targetStores === [] && $recommendedAction === 'monitor_concentrated_activity') {
            $referenceStore = is_string($drift['reference_store_fingerprint'] ?? null)
                ? trim((string) $drift['reference_store_fingerprint'])
                : '';

            if ($referenceStore !== '') {
                $targetStores = [$referenceStore];
            }
        }

        sort($targetStores);
        $response = match ($recommendedAction) {
            'investigate_store_dropout' => [
                'response_mode' => 'contain_store_dropout',
                'escalation_level' => 'high',
                'should_deny_remote_mutations' => true,
                'remote_mutation_denial_reason_code' => 'distributed_store_dropout_guard',
                'remote_mutation_scope_policy' => 'deny_all',
                'allowed_remote_mutation_scopes' => [],
                'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                'scope_denial_reason_codes' => [
                    'all' => 'distributed_store_dropout_guard_all_scope',
                    'sessions' => 'distributed_store_dropout_guard_sessions_scope',
                    'trusted-devices' => 'distributed_store_dropout_guard_trusted_devices_scope',
                ],
                'degraded_scope_profiles' => [
                    'all_remote_mutations:deny_all',
                ],
                'authorization_mode_scope_policies' => [],
                'next_step' => 'block_remote_mutations_until_store_recovers',
                'target_store_fingerprints' => $targetStores,
            ],
            'rebalance_partial_visibility' => [
                'response_mode' => 'guard_remote_mutations',
                'escalation_level' => 'medium',
                'should_deny_remote_mutations' => true,
                'remote_mutation_denial_reason_code' => 'distributed_partial_visibility_guard',
                'remote_mutation_scope_policy' => 'sessions_only',
                'allowed_remote_mutation_scopes' => ['sessions'],
                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                'scope_denial_reason_codes' => [
                    'all' => 'distributed_partial_visibility_guard_all_scope',
                    'trusted-devices' => 'distributed_partial_visibility_guard_trusted_devices_scope',
                ],
                'degraded_scope_profiles' => [
                    'direct_admin:all->sessions_only',
                    'direct_admin:trusted-devices->deny_all',
                    'delegated_admin:*->deny_all',
                    'delegated_admin_sessions_scope_target:sessions->sessions_only',
                ],
                'authorization_mode_scope_policies' => [
                    'direct_admin' => [
                        'remote_mutation_scope_policy' => 'sessions_only',
                        'allowed_remote_mutation_scopes' => ['sessions'],
                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                        'scope_denial_reason_codes' => [
                            'all' => 'distributed_partial_visibility_guard_direct_admin_all_scope',
                            'trusted-devices' => 'distributed_partial_visibility_guard_direct_admin_trusted_devices_scope',
                        ],
                        'policy_reason_code' => 'distributed_partial_visibility_guard_direct_admin_policy',
                        'privilege_scope_policies' => [
                            'privileged_admin' => [
                                'remote_mutation_scope_policy' => 'sessions_only',
                                'allowed_remote_mutation_scopes' => ['sessions'],
                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                'scope_denial_reason_codes' => [
                                    'all' => 'distributed_partial_visibility_guard_privileged_admin_all_scope',
                                    'trusted-devices' => 'distributed_partial_visibility_guard_privileged_admin_trusted_devices_scope',
                                ],
                                'policy_reason_code' => 'distributed_partial_visibility_guard_privileged_admin_policy',
                                'target_relation_scope_policies' => [
                                    'self_governed' => [
                                        'remote_mutation_scope_policy' => 'allow_all',
                                        'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                        'denied_remote_mutation_scopes' => [],
                                        'scope_denial_reason_codes' => [],
                                        'policy_reason_code' => 'distributed_partial_visibility_guard_privileged_admin_self_governed_policy',
                                    ],
                                    'direct_administrative_target' => [
                                        'remote_mutation_scope_policy' => 'sessions_only',
                                        'allowed_remote_mutation_scopes' => ['sessions'],
                                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_partial_visibility_guard_privileged_admin_direct_target_all_scope',
                                            'trusted-devices' => 'distributed_partial_visibility_guard_privileged_admin_direct_target_trusted_devices_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_partial_visibility_guard_privileged_admin_direct_target_policy',
                                        'target_scope_relation_policies' => [
                                            'direct_admin_full_scope_target' => [
                                                'remote_mutation_scope_policy' => 'sessions_only',
                                                'allowed_remote_mutation_scopes' => ['sessions'],
                                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_partial_visibility_guard_privileged_admin_direct_full_target_all_scope',
                                                    'trusted-devices' => 'distributed_partial_visibility_guard_privileged_admin_direct_full_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_partial_visibility_guard_privileged_admin_direct_full_target_policy',
                                            ],
                                            'direct_admin_sessions_scope_target' => [
                                                'remote_mutation_scope_policy' => 'sessions_only',
                                                'allowed_remote_mutation_scopes' => ['sessions'],
                                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_partial_visibility_guard_privileged_admin_direct_sessions_target_all_scope',
                                                    'trusted-devices' => 'distributed_partial_visibility_guard_privileged_admin_direct_sessions_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_partial_visibility_guard_privileged_admin_direct_sessions_target_policy',
                                            ],
                                            'direct_admin_trusted_devices_scope_target' => [
                                                'remote_mutation_scope_policy' => 'deny_all',
                                                'allowed_remote_mutation_scopes' => [],
                                                'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_partial_visibility_guard_privileged_admin_direct_trusted_target_all_scope',
                                                    'sessions' => 'distributed_partial_visibility_guard_privileged_admin_direct_trusted_target_sessions_scope',
                                                    'trusted-devices' => 'distributed_partial_visibility_guard_privileged_admin_direct_trusted_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_partial_visibility_guard_privileged_admin_direct_trusted_target_policy',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'delegated_admin' => [
                        'remote_mutation_scope_policy' => 'deny_all',
                        'allowed_remote_mutation_scopes' => [],
                        'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                        'scope_denial_reason_codes' => [
                            'all' => 'distributed_partial_visibility_guard_delegated_admin_all_scope',
                            'sessions' => 'distributed_partial_visibility_guard_delegated_admin_sessions_scope',
                            'trusted-devices' => 'distributed_partial_visibility_guard_delegated_admin_trusted_devices_scope',
                        ],
                        'policy_reason_code' => 'distributed_partial_visibility_guard_delegated_admin_policy',
                        'privilege_scope_policies' => [
                            'delegated_support' => [
                                'remote_mutation_scope_policy' => 'deny_all',
                                'allowed_remote_mutation_scopes' => [],
                                'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                'scope_denial_reason_codes' => [
                                    'all' => 'distributed_partial_visibility_guard_delegated_support_all_scope',
                                    'sessions' => 'distributed_partial_visibility_guard_delegated_support_sessions_scope',
                                    'trusted-devices' => 'distributed_partial_visibility_guard_delegated_support_trusted_devices_scope',
                                ],
                                'policy_reason_code' => 'distributed_partial_visibility_guard_delegated_support_policy',
                                'target_relation_scope_policies' => [
                                    'self_governed' => [
                                        'remote_mutation_scope_policy' => 'sessions_only',
                                        'allowed_remote_mutation_scopes' => ['sessions'],
                                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_partial_visibility_guard_delegated_support_self_governed_all_scope',
                                            'trusted-devices' => 'distributed_partial_visibility_guard_delegated_support_self_governed_trusted_devices_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_partial_visibility_guard_delegated_support_self_governed_policy',
                                    ],
                                    'delegated_administrative_target' => [
                                        'remote_mutation_scope_policy' => 'deny_all',
                                        'allowed_remote_mutation_scopes' => [],
                                        'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_partial_visibility_guard_delegated_support_delegated_target_all_scope',
                                            'sessions' => 'distributed_partial_visibility_guard_delegated_support_delegated_target_sessions_scope',
                                            'trusted-devices' => 'distributed_partial_visibility_guard_delegated_support_delegated_target_trusted_devices_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_partial_visibility_guard_delegated_support_delegated_target_policy',
                                        'target_scope_relation_policies' => [
                                            'delegated_admin_full_scope_target' => [
                                                'remote_mutation_scope_policy' => 'deny_all',
                                                'allowed_remote_mutation_scopes' => [],
                                                'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_partial_visibility_guard_delegated_support_delegated_full_target_all_scope',
                                                    'sessions' => 'distributed_partial_visibility_guard_delegated_support_delegated_full_target_sessions_scope',
                                                    'trusted-devices' => 'distributed_partial_visibility_guard_delegated_support_delegated_full_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_partial_visibility_guard_delegated_support_delegated_full_target_policy',
                                            ],
                                            'delegated_admin_sessions_scope_target' => [
                                                'remote_mutation_scope_policy' => 'sessions_only',
                                                'allowed_remote_mutation_scopes' => ['sessions'],
                                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_partial_visibility_guard_delegated_support_delegated_sessions_target_all_scope',
                                                    'trusted-devices' => 'distributed_partial_visibility_guard_delegated_support_delegated_sessions_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_partial_visibility_guard_delegated_support_delegated_sessions_target_policy',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'none' => [
                        'remote_mutation_scope_policy' => 'deny_all',
                        'allowed_remote_mutation_scopes' => [],
                        'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                        'scope_denial_reason_codes' => [
                            'all' => 'distributed_partial_visibility_guard_untrusted_all_scope',
                            'sessions' => 'distributed_partial_visibility_guard_untrusted_sessions_scope',
                            'trusted-devices' => 'distributed_partial_visibility_guard_untrusted_trusted_devices_scope',
                        ],
                        'policy_reason_code' => 'distributed_partial_visibility_guard_untrusted_policy',
                    ],
                ],
                'next_step' => 'restore_recent_store_visibility',
                'target_store_fingerprints' => $targetStores,
            ],
            'monitor_concentrated_activity' => [
                'response_mode' => 'observe_concentrated_activity',
                'escalation_level' => 'low',
                'should_deny_remote_mutations' => false,
                'remote_mutation_denial_reason_code' => 'distributed_concentrated_activity_guard',
                'remote_mutation_scope_policy' => 'allow_all',
                'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                'denied_remote_mutation_scopes' => [],
                'scope_denial_reason_codes' => [],
                'degraded_scope_profiles' => [
                    'direct_admin:direct_sessions->sessions_only',
                    'direct_admin:direct_trusted_devices->trusted_devices_only',
                    'delegated_admin:all->sessions_only',
                    'delegated_admin:trusted-devices->deny_all',
                    'delegated_support:self_governed->allow_all',
                    'untrusted:*->deny_all',
                ],
                'authorization_mode_scope_policies' => [
                    'direct_admin' => [
                        'remote_mutation_scope_policy' => 'allow_all',
                        'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                        'denied_remote_mutation_scopes' => [],
                        'scope_denial_reason_codes' => [],
                        'policy_reason_code' => 'distributed_concentrated_activity_guard_direct_admin_policy',
                        'target_relation_scope_policies' => [
                            'direct_administrative_target' => [
                                'remote_mutation_scope_policy' => 'allow_all',
                                'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                'denied_remote_mutation_scopes' => [],
                                'scope_denial_reason_codes' => [],
                                'policy_reason_code' => 'distributed_concentrated_activity_guard_direct_target_policy',
                                'target_scope_relation_policies' => [
                                    'direct_admin_full_scope_target' => [
                                        'remote_mutation_scope_policy' => 'allow_all',
                                        'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                        'denied_remote_mutation_scopes' => [],
                                        'scope_denial_reason_codes' => [],
                                        'policy_reason_code' => 'distributed_concentrated_activity_guard_direct_full_target_policy',
                                    ],
                                    'direct_admin_sessions_scope_target' => [
                                        'remote_mutation_scope_policy' => 'sessions_only',
                                        'allowed_remote_mutation_scopes' => ['sessions'],
                                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_concentrated_activity_guard_direct_sessions_target_all_scope',
                                            'trusted-devices' => 'distributed_concentrated_activity_guard_direct_sessions_target_trusted_devices_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_concentrated_activity_guard_direct_sessions_target_policy',
                                    ],
                                    'direct_admin_trusted_devices_scope_target' => [
                                        'remote_mutation_scope_policy' => 'trusted_devices_only',
                                        'allowed_remote_mutation_scopes' => ['trusted-devices'],
                                        'denied_remote_mutation_scopes' => ['all', 'sessions'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_concentrated_activity_guard_direct_trusted_target_all_scope',
                                            'sessions' => 'distributed_concentrated_activity_guard_direct_trusted_target_sessions_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_concentrated_activity_guard_direct_trusted_target_policy',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'delegated_admin' => [
                        'remote_mutation_scope_policy' => 'sessions_only',
                        'allowed_remote_mutation_scopes' => ['sessions'],
                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                        'scope_denial_reason_codes' => [
                            'all' => 'distributed_concentrated_activity_guard_delegated_admin_all_scope',
                            'trusted-devices' => 'distributed_concentrated_activity_guard_delegated_admin_trusted_devices_scope',
                        ],
                        'policy_reason_code' => 'distributed_concentrated_activity_guard_delegated_admin_policy',
                        'privilege_scope_policies' => [
                            'delegated_support' => [
                                'remote_mutation_scope_policy' => 'sessions_only',
                                'allowed_remote_mutation_scopes' => ['sessions'],
                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                'scope_denial_reason_codes' => [
                                    'all' => 'distributed_concentrated_activity_guard_delegated_support_all_scope',
                                    'trusted-devices' => 'distributed_concentrated_activity_guard_delegated_support_trusted_devices_scope',
                                ],
                                'policy_reason_code' => 'distributed_concentrated_activity_guard_delegated_support_policy',
                                'target_relation_scope_policies' => [
                                    'self_governed' => [
                                        'remote_mutation_scope_policy' => 'allow_all',
                                        'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                        'denied_remote_mutation_scopes' => [],
                                        'scope_denial_reason_codes' => [],
                                        'policy_reason_code' => 'distributed_concentrated_activity_guard_delegated_support_self_governed_policy',
                                    ],
                                    'delegated_administrative_target' => [
                                        'remote_mutation_scope_policy' => 'sessions_only',
                                        'allowed_remote_mutation_scopes' => ['sessions'],
                                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_concentrated_activity_guard_delegated_support_delegated_target_all_scope',
                                            'trusted-devices' => 'distributed_concentrated_activity_guard_delegated_support_delegated_target_trusted_devices_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_concentrated_activity_guard_delegated_support_delegated_target_policy',
                                        'target_scope_relation_policies' => [
                                            'delegated_admin_full_scope_target' => [
                                                'remote_mutation_scope_policy' => 'sessions_only',
                                                'allowed_remote_mutation_scopes' => ['sessions'],
                                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_concentrated_activity_guard_delegated_support_delegated_full_target_all_scope',
                                                    'trusted-devices' => 'distributed_concentrated_activity_guard_delegated_support_delegated_full_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_concentrated_activity_guard_delegated_support_delegated_full_target_policy',
                                            ],
                                            'delegated_admin_sessions_scope_target' => [
                                                'remote_mutation_scope_policy' => 'sessions_only',
                                                'allowed_remote_mutation_scopes' => ['sessions'],
                                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_concentrated_activity_guard_delegated_support_delegated_sessions_target_all_scope',
                                                    'trusted-devices' => 'distributed_concentrated_activity_guard_delegated_support_delegated_sessions_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_concentrated_activity_guard_delegated_support_delegated_sessions_target_policy',
                                            ],
                                            'delegated_admin_trusted_devices_scope_target' => [
                                                'remote_mutation_scope_policy' => 'deny_all',
                                                'allowed_remote_mutation_scopes' => [],
                                                'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_concentrated_activity_guard_delegated_support_delegated_trusted_target_all_scope',
                                                    'sessions' => 'distributed_concentrated_activity_guard_delegated_support_delegated_trusted_target_sessions_scope',
                                                    'trusted-devices' => 'distributed_concentrated_activity_guard_delegated_support_delegated_trusted_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_concentrated_activity_guard_delegated_support_delegated_trusted_target_policy',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'none' => [
                        'remote_mutation_scope_policy' => 'deny_all',
                        'allowed_remote_mutation_scopes' => [],
                        'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                        'scope_denial_reason_codes' => [
                            'all' => 'distributed_concentrated_activity_guard_untrusted_all_scope',
                            'sessions' => 'distributed_concentrated_activity_guard_untrusted_sessions_scope',
                            'trusted-devices' => 'distributed_concentrated_activity_guard_untrusted_trusted_devices_scope',
                        ],
                        'policy_reason_code' => 'distributed_concentrated_activity_guard_untrusted_policy',
                    ],
                ],
                'next_step' => 'verify_secondary_store_participation',
                'target_store_fingerprints' => $targetStores,
            ],
            'monitor_recent_lag' => [
                'response_mode' => 'observe_recent_lag',
                'escalation_level' => $severity === 'none' ? 'low' : $severity,
                'should_deny_remote_mutations' => false,
                'remote_mutation_denial_reason_code' => 'distributed_recent_lag_monitor',
                'remote_mutation_scope_policy' => 'allow_all',
                'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                'denied_remote_mutation_scopes' => [],
                'scope_denial_reason_codes' => [],
                'degraded_scope_profiles' => [
                    'direct_admin:direct_sessions->sessions_only',
                    'direct_admin:direct_trusted_devices->trusted_devices_only',
                    'delegated_admin:all->sessions_only',
                    'delegated_admin:trusted-devices->deny_all',
                    'delegated_support:self_governed->sessions_only',
                    'untrusted:*->deny_all',
                ],
                'authorization_mode_scope_policies' => [
                    'direct_admin' => [
                        'remote_mutation_scope_policy' => 'allow_all',
                        'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                        'denied_remote_mutation_scopes' => [],
                        'scope_denial_reason_codes' => [],
                        'policy_reason_code' => 'distributed_recent_lag_guard_direct_admin_policy',
                        'target_relation_scope_policies' => [
                            'direct_administrative_target' => [
                                'remote_mutation_scope_policy' => 'allow_all',
                                'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                'denied_remote_mutation_scopes' => [],
                                'scope_denial_reason_codes' => [],
                                'policy_reason_code' => 'distributed_recent_lag_guard_direct_target_policy',
                                'target_scope_relation_policies' => [
                                    'direct_admin_full_scope_target' => [
                                        'remote_mutation_scope_policy' => 'allow_all',
                                        'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                        'denied_remote_mutation_scopes' => [],
                                        'scope_denial_reason_codes' => [],
                                        'policy_reason_code' => 'distributed_recent_lag_guard_direct_full_target_policy',
                                    ],
                                    'direct_admin_sessions_scope_target' => [
                                        'remote_mutation_scope_policy' => 'sessions_only',
                                        'allowed_remote_mutation_scopes' => ['sessions'],
                                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_recent_lag_guard_direct_sessions_target_all_scope',
                                            'trusted-devices' => 'distributed_recent_lag_guard_direct_sessions_target_trusted_devices_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_recent_lag_guard_direct_sessions_target_policy',
                                    ],
                                    'direct_admin_trusted_devices_scope_target' => [
                                        'remote_mutation_scope_policy' => 'trusted_devices_only',
                                        'allowed_remote_mutation_scopes' => ['trusted-devices'],
                                        'denied_remote_mutation_scopes' => ['all', 'sessions'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_recent_lag_guard_direct_trusted_target_all_scope',
                                            'sessions' => 'distributed_recent_lag_guard_direct_trusted_target_sessions_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_recent_lag_guard_direct_trusted_target_policy',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'delegated_admin' => [
                        'remote_mutation_scope_policy' => 'sessions_only',
                        'allowed_remote_mutation_scopes' => ['sessions'],
                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                        'scope_denial_reason_codes' => [
                            'all' => 'distributed_recent_lag_guard_delegated_admin_all_scope',
                            'trusted-devices' => 'distributed_recent_lag_guard_delegated_admin_trusted_devices_scope',
                        ],
                        'policy_reason_code' => 'distributed_recent_lag_guard_delegated_admin_policy',
                        'privilege_scope_policies' => [
                            'delegated_support' => [
                                'remote_mutation_scope_policy' => 'sessions_only',
                                'allowed_remote_mutation_scopes' => ['sessions'],
                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                'scope_denial_reason_codes' => [
                                    'all' => 'distributed_recent_lag_guard_delegated_support_all_scope',
                                    'trusted-devices' => 'distributed_recent_lag_guard_delegated_support_trusted_devices_scope',
                                ],
                                'policy_reason_code' => 'distributed_recent_lag_guard_delegated_support_policy',
                                'target_relation_scope_policies' => [
                                    'self_governed' => [
                                        'remote_mutation_scope_policy' => 'sessions_only',
                                        'allowed_remote_mutation_scopes' => ['sessions'],
                                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_recent_lag_guard_delegated_support_self_governed_all_scope',
                                            'trusted-devices' => 'distributed_recent_lag_guard_delegated_support_self_governed_trusted_devices_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_recent_lag_guard_delegated_support_self_governed_policy',
                                    ],
                                    'delegated_administrative_target' => [
                                        'remote_mutation_scope_policy' => 'sessions_only',
                                        'allowed_remote_mutation_scopes' => ['sessions'],
                                        'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                        'scope_denial_reason_codes' => [
                                            'all' => 'distributed_recent_lag_guard_delegated_support_delegated_target_all_scope',
                                            'trusted-devices' => 'distributed_recent_lag_guard_delegated_support_delegated_target_trusted_devices_scope',
                                        ],
                                        'policy_reason_code' => 'distributed_recent_lag_guard_delegated_support_delegated_target_policy',
                                        'target_scope_relation_policies' => [
                                            'delegated_admin_full_scope_target' => [
                                                'remote_mutation_scope_policy' => 'sessions_only',
                                                'allowed_remote_mutation_scopes' => ['sessions'],
                                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_recent_lag_guard_delegated_support_delegated_full_target_all_scope',
                                                    'trusted-devices' => 'distributed_recent_lag_guard_delegated_support_delegated_full_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_recent_lag_guard_delegated_support_delegated_full_target_policy',
                                            ],
                                            'delegated_admin_sessions_scope_target' => [
                                                'remote_mutation_scope_policy' => 'sessions_only',
                                                'allowed_remote_mutation_scopes' => ['sessions'],
                                                'denied_remote_mutation_scopes' => ['all', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_recent_lag_guard_delegated_support_delegated_sessions_target_all_scope',
                                                    'trusted-devices' => 'distributed_recent_lag_guard_delegated_support_delegated_sessions_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_recent_lag_guard_delegated_support_delegated_sessions_target_policy',
                                            ],
                                            'delegated_admin_trusted_devices_scope_target' => [
                                                'remote_mutation_scope_policy' => 'deny_all',
                                                'allowed_remote_mutation_scopes' => [],
                                                'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                                                'scope_denial_reason_codes' => [
                                                    'all' => 'distributed_recent_lag_guard_delegated_support_delegated_trusted_target_all_scope',
                                                    'sessions' => 'distributed_recent_lag_guard_delegated_support_delegated_trusted_target_sessions_scope',
                                                    'trusted-devices' => 'distributed_recent_lag_guard_delegated_support_delegated_trusted_target_trusted_devices_scope',
                                                ],
                                                'policy_reason_code' => 'distributed_recent_lag_guard_delegated_support_delegated_trusted_target_policy',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'none' => [
                        'remote_mutation_scope_policy' => 'deny_all',
                        'allowed_remote_mutation_scopes' => [],
                        'denied_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                        'scope_denial_reason_codes' => [
                            'all' => 'distributed_recent_lag_guard_untrusted_all_scope',
                            'sessions' => 'distributed_recent_lag_guard_untrusted_sessions_scope',
                            'trusted-devices' => 'distributed_recent_lag_guard_untrusted_trusted_devices_scope',
                        ],
                        'policy_reason_code' => 'distributed_recent_lag_guard_untrusted_policy',
                    ],
                ],
                'next_step' => 'review_lagging_store_health',
                'target_store_fingerprints' => $targetStores,
            ],
            'single_store_baseline' => [
                'response_mode' => 'single_store_baseline',
                'escalation_level' => 'none',
                'should_deny_remote_mutations' => false,
                'remote_mutation_denial_reason_code' => 'single_store_baseline',
                'remote_mutation_scope_policy' => 'allow_all',
                'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                'denied_remote_mutation_scopes' => [],
                'scope_denial_reason_codes' => [],
                'degraded_scope_profiles' => [],
                'authorization_mode_scope_policies' => [],
                'next_step' => 'confirm_single_store_topology',
                'target_store_fingerprints' => [],
            ],
            default => [
                'response_mode' => 'normal_operations',
                'escalation_level' => 'none',
                'should_deny_remote_mutations' => false,
                'remote_mutation_denial_reason_code' => 'none',
                'remote_mutation_scope_policy' => 'allow_all',
                'allowed_remote_mutation_scopes' => ['all', 'sessions', 'trusted-devices'],
                'denied_remote_mutation_scopes' => [],
                'scope_denial_reason_codes' => [],
                'degraded_scope_profiles' => [],
                'authorization_mode_scope_policies' => [],
                'next_step' => 'continue_normal_operations',
                'target_store_fingerprints' => [],
            ],
        };

        $response['target_store_assessments'] = $this->targetStoreAssessments(
            (array) ($drift['store_assessments'] ?? []),
            (array) ($response['target_store_fingerprints'] ?? []),
        );
        $response['mutation_scope_profiles'] = $this->mutationScopeProfiles(
            $response,
            (array) ($response['target_store_fingerprints'] ?? []),
            (array) ($response['target_store_assessments'] ?? []),
        );

        return $response;
    }

    /**
     * @param list<mixed> $storeAssessments
     * @param list<mixed> $targetStores
     * @return list<array<string, mixed>>
     */
    private function targetStoreAssessments(array $storeAssessments, array $targetStores): array
    {
        $normalizedTargets = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $targetStores,
        ), static fn (string $value): bool => $value !== ''));

        if ($normalizedTargets === []) {
            return [];
        }

        return array_values(array_filter(
            $storeAssessments,
            static fn (mixed $assessment): bool => is_array($assessment)
                && in_array((string) ($assessment['store_fingerprint'] ?? ''), $normalizedTargets, true),
        ));
    }

    /**
     * @param array<string, mixed> $operationalResponse
     * @param list<mixed> $targetStores
     * @param list<mixed> $targetStoreAssessments
     * @return list<array<string, mixed>>
     */
    private function mutationScopeProfiles(
        array $operationalResponse,
        array $targetStores,
        array $targetStoreAssessments,
    ): array {
        $allowedScopes = array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            (array) ($operationalResponse['allowed_remote_mutation_scopes'] ?? []),
        ));
        $deniedScopes = array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            (array) ($operationalResponse['denied_remote_mutation_scopes'] ?? []),
        ));
        /** @var array<string, string> $scopeReasonCodes */
        $scopeReasonCodes = array_filter(
            (array) ($operationalResponse['scope_denial_reason_codes'] ?? []),
            static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );

        $profiles = [];

        foreach (['all', 'sessions', 'trusted-devices'] as $scope) {
            $profiles[] = [
                'scope' => $scope,
                'mutation_kind' => match ($scope) {
                    'sessions' => 'session_revocation',
                    'trusted-devices' => 'trusted_device_revocation',
                    default => 'aggregated_device_revocation',
                },
                'response_mode' => (string) ($operationalResponse['response_mode'] ?? 'normal_operations'),
                'escalation_level' => (string) ($operationalResponse['escalation_level'] ?? 'none'),
                'scope_policy' => (string) ($operationalResponse['remote_mutation_scope_policy'] ?? 'allow_all'),
                'policy_source' => 'global_scope_policy',
                'should_deny' => in_array($scope, $deniedScopes, true),
                'reason_code' => in_array($scope, $deniedScopes, true)
                    ? ($scopeReasonCodes[$scope] ?? $operationalResponse['remote_mutation_denial_reason_code'] ?? 'distributed_remote_mutation_guard')
                    : null,
                'allowed_scopes' => $allowedScopes,
                'denied_scopes' => $deniedScopes,
                'target_store_fingerprints' => array_values(array_map('strval', $targetStores)),
                'target_store_assessments' => $targetStoreAssessments,
            ];
        }

        return $profiles;
    }

    /**
     * @param array<string, array<string, mixed>> $storeCohorts
     * @return list<array<string, mixed>>
     */
    private function normalizeStoreCohorts(array $storeCohorts): array
    {
        $normalized = [];

        foreach ($storeCohorts as $cohort) {
            $cohort['unique_correlation_ids'] = count((array) ($cohort['unique_correlation_ids'] ?? []));
            $cohort['unique_operation_ids'] = count((array) ($cohort['unique_operation_ids'] ?? []));
            ksort($cohort['outcomes']);
            ksort($cohort['scopes']);
            ksort($cohort['authorization_modes']);
            ksort($cohort['mutation_kinds']);
            ksort($cohort['distributed_guard_policy_sources']);
            ksort($cohort['distributed_guard_policy_reason_codes']);
            ksort($cohort['distributed_guard_reason_codes']);
            $normalized[] = $cohort;
        }

        usort($normalized, static function (array $left, array $right): int {
            $eventComparison = ((int) ($right['event_count'] ?? 0)) <=> ((int) ($left['event_count'] ?? 0));

            if ($eventComparison !== 0) {
                return $eventComparison;
            }

            return strcmp((string) ($left['store_fingerprint'] ?? ''), (string) ($right['store_fingerprint'] ?? ''));
        });

        return $normalized;
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
