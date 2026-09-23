<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Devices\TrustedDevice;
use Quantum\Auth\Devices\TrustedDevicePublicId;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
use Quantum\Console\Commands\AuthSecurityCenterReportCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthSecurityCenterReportCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-auth-security-center-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);

        $escapedBasePath = var_export($this->basePath, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$config = \$app->make(ConfigRepository::class);
\$config->set('auth.session.driver', 'file');
\$config->set('auth.trusted_devices.driver', 'file');

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_generates_a_safe_summary_without_identity_detail(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedSecurityCenterFixtures($app, $seedNow);

        $command = new AuthSecurityCenterReportCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:report',
                '--now=' . $seedNow,
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Reporte de security center generado correctamente.', $output->stdout());
        self::assertStringContainsString('Sesiones activas: 4', $output->stdout());
        self::assertStringContainsString('Trusted devices activos: 1', $output->stdout());
        self::assertStringContainsString('Identidades unicas: 3', $output->stdout());
        self::assertStringContainsString('Dispositivos agregados: 4', $output->stdout());
        self::assertStringContainsString('Agregados trusted: 1', $output->stdout());
        self::assertStringContainsString('Agregados de management elevado: 1', $output->stdout());
        self::assertStringContainsString('Sesiones con management gobernado: 2', $output->stdout());
        self::assertStringContainsString('Identidades con management gobernado: 2', $output->stdout());
        self::assertStringContainsString('Sesiones direct_admin: 1', $output->stdout());
        self::assertStringContainsString('Sesiones delegated_admin: 1', $output->stdout());
        self::assertStringContainsString('Metricas administrativas: authorized=2 unauthorized=0 direct=1 delegated=1', $output->stdout());
        self::assertStringNotContainsString('Detalle para', $output->stdout());
        self::assertStringNotContainsString('session_public_ids=', $output->stdout());
        self::assertStringNotContainsString('trusted_device_public_id=', $output->stdout());
        self::assertStringNotContainsString('Actores administrativos gobernados:', $output->stdout());
    }

    public function test_it_summarizes_longitudinal_administrative_metrics_when_audit_log_source_is_provided(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedSecurityCenterFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-revoke.jsonl';
        $this->seedLongitudinalAuditTrail($auditLogPath, $seedNow);

        $command = new AuthSecurityCenterReportCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:report',
                '--now=' . $seedNow,
                '--audit-log-source=' . $auditLogPath,
                '--verbose',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Metricas longitudinales: events=3 executed=1 dry_run=1 rejected=1', $output->stdout());
        self::assertStringContainsString('Cohortes distribuidas: stores=2 top_store=fingerprint-a events=2 topology=shared_file_store_candidate', $output->stdout());
        self::assertStringContainsString('Ventanas distribuidas: 5m=1 15m=2 60m=3', $output->stdout());
        self::assertStringContainsString('Consolidacion temporal por store: stores=2 top_recent_store=fingerprint-a 15m=1 60m=2', $output->stdout());
        self::assertStringContainsString('Resumen multi-store: profile=distributed active_15m=2/2 spread=795s', $output->stdout());
        self::assertStringContainsString('Activity drift: detected=yes profile=recent_lag lagging=1 inactive_15m=0 gap=795s action=monitor_recent_lag response=observe_recent_lag deny_remote=no', $output->stdout());
        self::assertStringContainsString('Cohortes distribuidas por store:', $output->stdout());
        self::assertStringContainsString('- fingerprint=fingerprint-a | topology=shared_file_store_candidate | events=2 | executed=1 | dry_run=1 | rejected=0', $output->stdout());
        self::assertStringContainsString('- fingerprint=fingerprint-b | topology=mixed_driver_topology | events=1 | executed=0 | dry_run=0 | rejected=1', $output->stdout());
        self::assertStringContainsString('Ventanas temporales distribuidas:', $output->stdout());
        self::assertStringContainsString('- last_5m | events=1 | stores=1 | top_store=fingerprint-b | executed=0 | dry_run=0 | rejected=1', $output->stdout());
        self::assertStringContainsString('- last_15m | events=2 | stores=2 | top_store=fingerprint-a | executed=1 | dry_run=0 | rejected=1', $output->stdout());
        self::assertStringContainsString('- last_60m | events=3 | stores=2 | top_store=fingerprint-a | executed=1 | dry_run=1 | rejected=1', $output->stdout());
        self::assertStringContainsString('Consolidacion temporal por store:', $output->stdout());
        self::assertStringContainsString('- fingerprint=fingerprint-a | topology=shared_file_store_candidate | 5m=0 | 15m=1 | 60m=2 | latest=', $output->stdout());
        self::assertStringContainsString('- fingerprint=fingerprint-b | topology=mixed_driver_topology | 5m=1 | 15m=1 | 60m=1 | latest=', $output->stdout());
        self::assertStringContainsString('Resumen multi-store:', $output->stdout());
        self::assertStringContainsString('- profile=distributed | stores=2 | active_5m=1 | active_15m=2 | active_60m=2 | top_store=fingerprint-a | spread=795s', $output->stdout());
        self::assertStringContainsString('Activity drift:', $output->stdout());
        self::assertStringContainsString('- detected=yes | profile=recent_lag | severity=low | lagging=1 | inactive_15m=0 | inactive_60m=0 | gap=795s | action=monitor_recent_lag | reference_store=fingerprint-a | response=observe_recent_lag', $output->stdout());
        self::assertStringContainsString('Respuesta operativa:', $output->stdout());
        self::assertStringContainsString('- escalation=low | deny_remote=no | denial_reason=distributed_recent_lag_monitor | next_step=review_lagging_store_health | targets=1', $output->stdout());
        self::assertStringContainsString('Cobertura por ventana:', $output->stdout());
        self::assertStringContainsString('- last_5m | active=1/2 | inactive=1', $output->stdout());
        self::assertStringContainsString('- last_15m | active=2/2 | inactive=0', $output->stdout());
        self::assertStringContainsString('- last_60m | active=2/2 | inactive=0', $output->stdout());
        self::assertStringContainsString('Drift por store:', $output->stdout());
        self::assertStringContainsString('- fingerprint=fingerprint-a | topology=shared_file_store_candidate | status=lagging | gap=795s | 5m=0 | 15m=1 | 60m=2', $output->stdout());
        self::assertStringContainsString('- fingerprint=fingerprint-b | topology=mixed_driver_topology | status=healthy | gap=0s | 5m=1 | 15m=1 | 60m=1', $output->stdout());
    }

    public function test_it_emits_filtered_json_detail_with_public_ids_only_for_a_specific_identity(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedSecurityCenterFixtures($app, $seedNow);

        $command = new AuthSecurityCenterReportCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:report',
                '--now=' . $seedNow,
                '--correlation-id=report-detail-corr',
                '--identity=701',
                '--type=user',
                '--include-public-ids',
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $devices = $payload['devices'] ?? [];

        self::assertSame(0, $exitCode);
        self::assertSame('report-detail-corr', $payload['correlation_id'] ?? null);
        self::assertIsString($payload['operation_id'] ?? null);
        self::assertStringStartsWith('security-center-report-', (string) ($payload['operation_id'] ?? ''));
        self::assertSame(2, $payload['administrative_metrics']['governed_actor_identities_authorized'] ?? null);
        self::assertSame(0, $payload['administrative_metrics']['governed_actor_identities_unauthorized'] ?? null);
        self::assertSame(1, $payload['administrative_metrics']['authorization_modes']['direct_admin'] ?? null);
        self::assertSame(1, $payload['administrative_metrics']['authorization_modes']['delegated_admin'] ?? null);
        self::assertSame(1, $payload['administrative_metrics']['scope_coverage']['security_center_export'] ?? null);
        self::assertSame(2, $payload['administrative_metrics']['scope_coverage']['admin_device_management'] ?? null);
        self::assertSame('701', $payload['filters']['identity'] ?? null);
        self::assertSame('user', $payload['filters']['type'] ?? null);
        self::assertTrue((bool) ($payload['filters']['include_public_ids'] ?? false));
        self::assertSame('VoltStack', $payload['operational_context']['app_name'] ?? null);
        self::assertSame('local', $payload['operational_context']['app_env'] ?? null);
        self::assertSame('file', $payload['operational_context']['session_driver'] ?? null);
        self::assertSame('file', $payload['operational_context']['trusted_device_driver'] ?? null);
        self::assertSame('shared_file_store_candidate', $payload['operational_context']['store_topology'] ?? null);
        self::assertIsString($payload['operational_context']['store_fingerprint'] ?? null);
        self::assertStringEndsWith(
            DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'sessions',
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($payload['operational_context']['session_store_path'] ?? '')),
        );
        self::assertStringEndsWith(
            DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'trusted-devices',
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($payload['operational_context']['trusted_device_store_path'] ?? '')),
        );
        self::assertCount(2, $devices);

        $standard = array_values(array_filter($devices, static fn (array $device): bool => ($device['management_sensitivity'] ?? null) === 'standard'))[0] ?? null;
        $elevated = array_values(array_filter($devices, static fn (array $device): bool => ($device['management_sensitivity'] ?? null) === 'elevated'))[0] ?? null;

        self::assertIsArray($standard);
        self::assertIsArray($elevated);

        self::assertSame('current_device_management', $standard['management_reason_code'] ?? null);
        self::assertSame('identity_owner', $standard['management_authority'] ?? null);
        self::assertSame('identity_session', $standard['management_ownership_proof'] ?? null);
        self::assertSame('devref_report_alpha', $standard['device_reference'] ?? null);
        self::assertSame(['sess_pub_report_alpha'], $standard['session_public_ids'] ?? []);
        self::assertNull($standard['trusted_device_public_id'] ?? null);

        self::assertSame('trusted_device_management', $elevated['management_reason_code'] ?? null);
        self::assertSame('identity_owner', $elevated['management_authority'] ?? null);
        self::assertSame('identity_session', $elevated['management_ownership_proof'] ?? null);
        self::assertSame('devref_report_beta', $elevated['device_reference'] ?? null);
        self::assertSame(['sess_pub_report_beta'], $elevated['session_public_ids'] ?? []);
        self::assertSame('tdv_report_beta', $elevated['trusted_device_public_id'] ?? null);
    }

    public function test_it_exports_governed_management_actors_only_when_requested(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedSecurityCenterFixtures($app, $seedNow);

        $command = new AuthSecurityCenterReportCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:report',
                '--now=' . $seedNow,
                '--management-actors',
                '--include-public-ids',
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $actors = $payload['management_actors'] ?? [];

        self::assertSame(0, $exitCode);
        self::assertTrue((bool) ($payload['filters']['management_actors'] ?? false));
        self::assertSame(2, $payload['summary']['governed_management_sessions'] ?? null);
        self::assertSame(2, $payload['summary']['governed_management_identities'] ?? null);
        self::assertSame(1, $payload['summary']['direct_admin_sessions'] ?? null);
        self::assertSame(1, $payload['summary']['delegated_admin_sessions'] ?? null);
        self::assertCount(2, $actors);

        $byIdentity = [];

        foreach ($actors as $actor) {
            $byIdentity[(string) ($actor['identity_identifier'] ?? '')] = $actor;
        }

        self::assertSame('administrative_actor', $byIdentity['702']['management_authority'] ?? null);
        self::assertSame('identity_attributes', $byIdentity['702']['management_claims_source'] ?? null);
        self::assertSame('privileged_admin', $byIdentity['702']['management_privilege_level'] ?? null);
        self::assertTrue((bool) ($byIdentity['702']['management_authorized'] ?? false));
        self::assertSame('direct_admin', $byIdentity['702']['management_authorization_mode'] ?? null);
        self::assertNull($byIdentity['702']['management_authorization_reason_code'] ?? null);
        self::assertSame(['security_center_export', 'admin_device_management'], $byIdentity['702']['management_scopes'] ?? []);
        self::assertSame(['sess_pub_report_gamma'], $byIdentity['702']['session_public_ids'] ?? []);

        self::assertSame('administrative_actor', $byIdentity['703']['management_authority'] ?? null);
        self::assertSame('identity_attributes', $byIdentity['703']['management_claims_source'] ?? null);
        self::assertSame('delegated_support', $byIdentity['703']['management_privilege_level'] ?? null);
        self::assertTrue((bool) ($byIdentity['703']['management_authorized'] ?? false));
        self::assertSame('delegated_admin', $byIdentity['703']['management_authorization_mode'] ?? null);
        self::assertNull($byIdentity['703']['management_authorization_reason_code'] ?? null);
        self::assertSame(['admin_device_management'], $byIdentity['703']['management_scopes'] ?? []);
        self::assertSame(['sess_pub_report_delta'], $byIdentity['703']['session_public_ids'] ?? []);
    }

    public function test_it_exports_governed_actor_with_rejection_reason_when_claims_are_present_but_scope_is_insufficient(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedSecurityCenterFixtures($app, $seedNow);
        $this->seedRejectedGovernedActor($app, $seedNow);

        $command = new AuthSecurityCenterReportCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:report',
                '--now=' . $seedNow,
                '--management-actors',
                '--include-public-ids',
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $actors = $payload['management_actors'] ?? [];
        $byIdentity = [];

        foreach ($actors as $actor) {
            $byIdentity[(string) ($actor['identity_identifier'] ?? '')] = $actor;
        }

        self::assertSame(0, $exitCode);
        self::assertSame(3, $payload['summary']['governed_management_sessions'] ?? null);
        self::assertSame(3, $payload['summary']['governed_management_identities'] ?? null);
        self::assertCount(3, $actors);
        self::assertFalse((bool) ($byIdentity['704']['management_authorized'] ?? true));
        self::assertArrayHasKey('management_authorization_mode', $byIdentity['704']);
        self::assertNull($byIdentity['704']['management_authorization_mode']);
        self::assertSame(
            'missing_admin_device_management_scope',
            $byIdentity['704']['management_authorization_reason_code'] ?? null,
        );
        self::assertSame(['security_center_export'], $byIdentity['704']['management_scopes'] ?? []);
        self::assertSame(['sess_pub_report_epsilon'], $byIdentity['704']['session_public_ids'] ?? []);
    }

    public function test_it_persists_a_safe_summary_snapshot_when_export_log_is_requested(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedSecurityCenterFixtures($app, $seedNow);
        $exportLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'security-center-report.jsonl';

        $command = new AuthSecurityCenterReportCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:report',
                '--now=' . $seedNow,
                '--correlation-id=report-export-corr',
                '--export-log=' . $exportLogPath,
            ]),
            $output,
        );

        $events = $this->readExportEvents($exportLogPath);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Snapshot exportado en: ' . $exportLogPath, $output->stdout());
        self::assertCount(1, $events);
        self::assertSame('security_center_report_exported', $events[0]['event'] ?? null);
        self::assertSame('report-export-corr', $events[0]['correlation_id'] ?? null);
        self::assertIsString($events[0]['operation_id'] ?? null);
        self::assertStringStartsWith('security-center-report-', (string) ($events[0]['operation_id'] ?? ''));
        self::assertSame('exported', $events[0]['result'] ?? null);
        self::assertSame('report-export-corr', $events[0]['report']['correlation_id'] ?? null);
        self::assertSame($events[0]['operation_id'] ?? null, $events[0]['report']['operation_id'] ?? null);
        self::assertSame(2, $events[0]['report']['administrative_metrics']['governed_actor_identities_authorized'] ?? null);
        self::assertSame(1, $events[0]['report']['administrative_metrics']['authorization_modes']['direct_admin'] ?? null);
        self::assertSame(1, $events[0]['report']['administrative_metrics']['authorization_modes']['delegated_admin'] ?? null);
        self::assertSame('shared_file_store_candidate', $events[0]['report']['operational_context']['store_topology'] ?? null);
        self::assertSame('file', $events[0]['report']['operational_context']['session_driver'] ?? null);
        self::assertSame('file', $events[0]['report']['operational_context']['trusted_device_driver'] ?? null);
        self::assertSame(4, $events[0]['report']['summary']['active_sessions'] ?? null);
        self::assertSame(4, $events[0]['report']['summary']['aggregated_devices'] ?? null);
        self::assertArrayNotHasKey('devices', $events[0]['report']);
        self::assertArrayNotHasKey('management_actors', $events[0]['report']);
    }

    public function test_it_emits_longitudinal_metrics_from_audit_log_source_in_json_and_export(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedSecurityCenterFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-revoke.jsonl';
        $exportLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'security-center-report.jsonl';
        $this->seedLongitudinalAuditTrail($auditLogPath, $seedNow);

        $command = new AuthSecurityCenterReportCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:report',
                '--now=' . $seedNow,
                '--audit-log-source=' . $auditLogPath,
                '--export-log=' . $exportLogPath,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $events = $this->readExportEvents($exportLogPath);

        self::assertSame(0, $exitCode);
        self::assertSame($auditLogPath, $payload['filters']['audit_log_source'] ?? null);
        self::assertSame(3, $payload['longitudinal_metrics']['audit_event_count'] ?? null);
        self::assertSame(3, $payload['longitudinal_metrics']['unique_correlation_ids'] ?? null);
        self::assertSame(3, $payload['longitudinal_metrics']['unique_operation_ids'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['outcomes']['executed'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['outcomes']['dry_run'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['outcomes']['authorization_failed'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['scopes']['all'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['scopes']['sessions'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['scopes']['trusted-devices'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['actor_scope_profiles']['full'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['actor_scope_profiles']['sessions_only'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['actor_scope_profiles']['none'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['authorization_modes']['direct_admin'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['authorization_modes']['delegated_admin'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['authorization_modes']['none'] ?? null);
        self::assertSame(3, $payload['longitudinal_metrics']['affected_resources']['total'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['affected_resources']['sessions'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['affected_resources']['trusted-devices'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['observed_store_fingerprints'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['observed_topologies']['shared_file_store_candidate'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['observed_topologies']['mixed_driver_topology'] ?? null);
        self::assertSame($seedNow - 5, $payload['longitudinal_metrics']['latest_event_at'] ?? null);
        self::assertCount(3, $payload['longitudinal_metrics']['time_windows'] ?? []);
        self::assertCount(2, $payload['longitudinal_metrics']['store_time_windows'] ?? []);
        self::assertSame(2, $payload['longitudinal_metrics']['multi_store_summary']['observed_stores'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['multi_store_summary']['active_stores_last_5m'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['multi_store_summary']['active_stores_last_15m'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['multi_store_summary']['active_stores_last_60m'] ?? null);
        self::assertSame('fingerprint-a', $payload['longitudinal_metrics']['multi_store_summary']['top_recent_store_fingerprint'] ?? null);
        self::assertSame('shared_file_store_candidate', $payload['longitudinal_metrics']['multi_store_summary']['top_recent_store_topology'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['multi_store_summary']['top_recent_store_15m_events'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['multi_store_summary']['top_recent_store_60m_events'] ?? null);
        self::assertSame(795, $payload['longitudinal_metrics']['multi_store_summary']['latest_event_spread_seconds'] ?? null);
        self::assertSame('distributed', $payload['longitudinal_metrics']['multi_store_summary']['coordination_profile'] ?? null);
        self::assertTrue($payload['longitudinal_metrics']['activity_drift']['drift_detected'] ?? false);
        self::assertSame('recent_lag', $payload['longitudinal_metrics']['activity_drift']['drift_profile'] ?? null);
        self::assertSame('low', $payload['longitudinal_metrics']['activity_drift']['severity'] ?? null);
        self::assertSame('last_15m', $payload['longitudinal_metrics']['activity_drift']['reference_window'] ?? null);
        self::assertSame('monitor_recent_lag', $payload['longitudinal_metrics']['activity_drift']['recommended_action'] ?? null);
        self::assertSame('fingerprint-a', $payload['longitudinal_metrics']['activity_drift']['reference_store_fingerprint'] ?? null);
        self::assertSame('shared_file_store_candidate', $payload['longitudinal_metrics']['activity_drift']['reference_store_topology'] ?? null);
        self::assertSame('observe_recent_lag', $payload['longitudinal_metrics']['activity_drift']['operational_response']['response_mode'] ?? null);
        self::assertSame('low', $payload['longitudinal_metrics']['activity_drift']['operational_response']['escalation_level'] ?? null);
        self::assertFalse($payload['longitudinal_metrics']['activity_drift']['operational_response']['should_deny_remote_mutations'] ?? true);
        self::assertSame('distributed_recent_lag_monitor', $payload['longitudinal_metrics']['activity_drift']['operational_response']['remote_mutation_denial_reason_code'] ?? null);
        self::assertSame('review_lagging_store_health', $payload['longitudinal_metrics']['activity_drift']['operational_response']['next_step'] ?? null);
        self::assertSame(['fingerprint-a'], $payload['longitudinal_metrics']['activity_drift']['operational_response']['target_store_fingerprints'] ?? null);
        self::assertSame(795, $payload['longitudinal_metrics']['activity_drift']['max_event_gap_seconds'] ?? null);
        self::assertSame(0, $payload['longitudinal_metrics']['activity_drift']['inactive_stores_last_15m'] ?? null);
        self::assertSame(0, $payload['longitudinal_metrics']['activity_drift']['inactive_stores_last_60m'] ?? null);
        self::assertSame(['fingerprint-a'], $payload['longitudinal_metrics']['activity_drift']['lagging_store_fingerprints'] ?? null);
        self::assertSame([], $payload['longitudinal_metrics']['activity_drift']['stale_store_fingerprints'] ?? null);
        self::assertCount(3, $payload['longitudinal_metrics']['activity_drift']['window_coverage'] ?? []);
        self::assertSame('last_5m', $payload['longitudinal_metrics']['activity_drift']['window_coverage'][0]['label'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['activity_drift']['window_coverage'][0]['active_stores'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['activity_drift']['window_coverage'][0]['inactive_stores'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['activity_drift']['window_coverage'][0]['observed_stores'] ?? null);
        self::assertCount(2, $payload['longitudinal_metrics']['activity_drift']['store_assessments'] ?? []);
        self::assertSame('fingerprint-a', $payload['longitudinal_metrics']['activity_drift']['store_assessments'][0]['store_fingerprint'] ?? null);
        self::assertSame('lagging', $payload['longitudinal_metrics']['activity_drift']['store_assessments'][0]['status'] ?? null);
        self::assertSame(795, $payload['longitudinal_metrics']['activity_drift']['store_assessments'][0]['event_gap_seconds'] ?? null);
        self::assertSame(0, $payload['longitudinal_metrics']['activity_drift']['store_assessments'][0]['events_last_5m'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['activity_drift']['store_assessments'][0]['events_last_15m'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['activity_drift']['store_assessments'][0]['events_last_60m'] ?? null);
        self::assertSame('fingerprint-b', $payload['longitudinal_metrics']['activity_drift']['store_assessments'][1]['store_fingerprint'] ?? null);
        self::assertSame('healthy', $payload['longitudinal_metrics']['activity_drift']['store_assessments'][1]['status'] ?? null);
        self::assertSame(0, $payload['longitudinal_metrics']['activity_drift']['store_assessments'][1]['event_gap_seconds'] ?? null);
        self::assertSame('last_5m', $payload['longitudinal_metrics']['time_windows'][0]['label'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['time_windows'][0]['event_count'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['time_windows'][0]['observed_store_fingerprints'] ?? null);
        self::assertSame('fingerprint-b', $payload['longitudinal_metrics']['time_windows'][0]['top_store_fingerprint'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['time_windows'][0]['outcomes']['authorization_failed'] ?? null);
        self::assertSame('last_15m', $payload['longitudinal_metrics']['time_windows'][1]['label'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['time_windows'][1]['event_count'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['time_windows'][1]['observed_store_fingerprints'] ?? null);
        self::assertSame('fingerprint-a', $payload['longitudinal_metrics']['time_windows'][1]['top_store_fingerprint'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['time_windows'][1]['outcomes']['executed'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['time_windows'][1]['outcomes']['authorization_failed'] ?? null);
        self::assertSame('last_60m', $payload['longitudinal_metrics']['time_windows'][2]['label'] ?? null);
        self::assertSame(3, $payload['longitudinal_metrics']['time_windows'][2]['event_count'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['time_windows'][2]['observed_store_fingerprints'] ?? null);
        self::assertSame('fingerprint-a', $payload['longitudinal_metrics']['time_windows'][2]['top_store_fingerprint'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['time_windows'][2]['outcomes']['dry_run'] ?? null);
        self::assertSame('fingerprint-a', $payload['longitudinal_metrics']['store_time_windows'][0]['store_fingerprint'] ?? null);
        self::assertSame('shared_file_store_candidate', $payload['longitudinal_metrics']['store_time_windows'][0]['store_topology'] ?? null);
        self::assertSame($seedNow - 800, $payload['longitudinal_metrics']['store_time_windows'][0]['latest_event_at'] ?? null);
        self::assertSame('last_5m', $payload['longitudinal_metrics']['store_time_windows'][0]['windows'][0]['label'] ?? null);
        self::assertSame(0, $payload['longitudinal_metrics']['store_time_windows'][0]['windows'][0]['event_count'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_time_windows'][0]['windows'][1]['event_count'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['store_time_windows'][0]['windows'][2]['event_count'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_time_windows'][0]['windows'][2]['outcomes']['dry_run'] ?? null);
        self::assertSame(3, $payload['longitudinal_metrics']['store_time_windows'][0]['windows'][2]['affected_resources']['total'] ?? null);
        self::assertSame('fingerprint-b', $payload['longitudinal_metrics']['store_time_windows'][1]['store_fingerprint'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_time_windows'][1]['windows'][0]['event_count'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_time_windows'][1]['windows'][1]['event_count'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_time_windows'][1]['windows'][2]['event_count'] ?? null);
        self::assertCount(2, $payload['longitudinal_metrics']['store_cohorts'] ?? []);
        self::assertSame('fingerprint-a', $payload['longitudinal_metrics']['store_cohorts'][0]['store_fingerprint'] ?? null);
        self::assertSame('shared_file_store_candidate', $payload['longitudinal_metrics']['store_cohorts'][0]['store_topology'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['store_cohorts'][0]['event_count'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['store_cohorts'][0]['unique_correlation_ids'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['store_cohorts'][0]['unique_operation_ids'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_cohorts'][0]['outcomes']['executed'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_cohorts'][0]['outcomes']['dry_run'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_cohorts'][0]['scopes']['all'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_cohorts'][0]['scopes']['sessions'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_cohorts'][0]['authorization_modes']['direct_admin'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_cohorts'][0]['authorization_modes']['delegated_admin'] ?? null);
        self::assertSame(2, $payload['longitudinal_metrics']['store_cohorts'][0]['affected_resources']['sessions'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_cohorts'][0]['affected_resources']['trusted-devices'] ?? null);
        self::assertSame(3, $payload['longitudinal_metrics']['store_cohorts'][0]['affected_resources']['total'] ?? null);
        self::assertSame($seedNow - 800, $payload['longitudinal_metrics']['store_cohorts'][0]['latest_event_at'] ?? null);
        self::assertSame('fingerprint-b', $payload['longitudinal_metrics']['store_cohorts'][1]['store_fingerprint'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_cohorts'][1]['event_count'] ?? null);
        self::assertSame(1, $payload['longitudinal_metrics']['store_cohorts'][1]['authorization_modes']['none'] ?? null);

        self::assertCount(1, $events);
        self::assertSame(3, $events[0]['report']['longitudinal_metrics']['audit_event_count'] ?? null);
        self::assertSame(3, $events[0]['report']['longitudinal_metrics']['affected_resources']['total'] ?? null);
        self::assertCount(2, $events[0]['report']['longitudinal_metrics']['store_cohorts'] ?? []);
        self::assertCount(3, $events[0]['report']['longitudinal_metrics']['time_windows'] ?? []);
        self::assertCount(2, $events[0]['report']['longitudinal_metrics']['store_time_windows'] ?? []);
        self::assertSame('distributed', $events[0]['report']['longitudinal_metrics']['multi_store_summary']['coordination_profile'] ?? null);
        self::assertSame('recent_lag', $events[0]['report']['longitudinal_metrics']['activity_drift']['drift_profile'] ?? null);
        self::assertSame('monitor_recent_lag', $events[0]['report']['longitudinal_metrics']['activity_drift']['recommended_action'] ?? null);
        self::assertSame('observe_recent_lag', $events[0]['report']['longitudinal_metrics']['activity_drift']['operational_response']['response_mode'] ?? null);
    }

    public function test_it_derives_a_guarding_operational_response_for_store_dropout(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedSecurityCenterFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-revoke-dropout.jsonl';
        $this->seedLongitudinalStoreDropoutAuditTrail($auditLogPath, $seedNow);

        $command = new AuthSecurityCenterReportCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:report',
                '--now=' . $seedNow,
                '--audit-log-source=' . $auditLogPath,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode);
        self::assertTrue($payload['longitudinal_metrics']['activity_drift']['drift_detected'] ?? false);
        self::assertSame('store_dropout', $payload['longitudinal_metrics']['activity_drift']['drift_profile'] ?? null);
        self::assertSame('high', $payload['longitudinal_metrics']['activity_drift']['severity'] ?? null);
        self::assertSame('investigate_store_dropout', $payload['longitudinal_metrics']['activity_drift']['recommended_action'] ?? null);
        self::assertSame(['fingerprint-a'], $payload['longitudinal_metrics']['activity_drift']['stale_store_fingerprints'] ?? null);
        self::assertSame('contain_store_dropout', $payload['longitudinal_metrics']['activity_drift']['operational_response']['response_mode'] ?? null);
        self::assertSame('high', $payload['longitudinal_metrics']['activity_drift']['operational_response']['escalation_level'] ?? null);
        self::assertTrue($payload['longitudinal_metrics']['activity_drift']['operational_response']['should_deny_remote_mutations'] ?? false);
        self::assertSame('distributed_store_dropout_guard', $payload['longitudinal_metrics']['activity_drift']['operational_response']['remote_mutation_denial_reason_code'] ?? null);
        self::assertSame('block_remote_mutations_until_store_recovers', $payload['longitudinal_metrics']['activity_drift']['operational_response']['next_step'] ?? null);
        self::assertSame(['fingerprint-a'], $payload['longitudinal_metrics']['activity_drift']['operational_response']['target_store_fingerprints'] ?? null);
    }

    public function test_it_persists_detailed_snapshot_only_when_identity_and_management_flags_are_requested(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedSecurityCenterFixtures($app, $seedNow);
        $exportLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'security-center-report.jsonl';

        $command = new AuthSecurityCenterReportCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:report',
                '--now=' . $seedNow,
                '--identity=701',
                '--type=user',
                '--include-public-ids',
                '--management-actors',
                '--export-log=' . $exportLogPath,
                '--json',
            ]),
            $output,
        );

        $events = $this->readExportEvents($exportLogPath);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $events);
        self::assertSame('701', $events[0]['report']['filters']['identity'] ?? null);
        self::assertTrue((bool) ($events[0]['report']['filters']['include_public_ids'] ?? false));
        self::assertTrue((bool) ($events[0]['report']['filters']['management_actors'] ?? false));
        self::assertCount(2, $events[0]['report']['devices'] ?? []);
        self::assertCount(0, $events[0]['report']['management_actors'] ?? []);

        $devices = $events[0]['report']['devices'] ?? [];
        $elevated = array_values(array_filter($devices, static fn (array $device): bool => ($device['management_sensitivity'] ?? null) === 'elevated'))[0] ?? null;

        self::assertIsArray($elevated);
        self::assertSame(['sess_pub_report_beta'], $elevated['session_public_ids'] ?? []);
        self::assertSame('tdv_report_beta', $elevated['trusted_device_public_id'] ?? null);
    }

    private function seedSecurityCenterFixtures(Application $app, int $seedNow): void
    {
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        $primaryIdentity = new GenericIdentity(
            identifier: new IdentityIdentifier('701'),
            type: 'user',
            attributes: ['name' => 'Primary Security User'],
        );
        $primaryReference = new IdentityReference($primaryIdentity->identifier(), $primaryIdentity->type());

        $secondaryIdentity = new GenericIdentity(
            identifier: new IdentityIdentifier('702'),
            type: 'user',
            attributes: [
                'name' => 'Secondary Security User',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => [
                    'security_center_export',
                    'admin_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        );
        $secondaryReference = new IdentityReference($secondaryIdentity->identifier(), $secondaryIdentity->type());

        $delegatedIdentity = new GenericIdentity(
            identifier: new IdentityIdentifier('703'),
            type: 'user',
            attributes: [
                'name' => 'Delegated Support',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'delegated_session',
                'auth_management_scopes' => [
                    'admin_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'delegated_support',
            ],
        );
        $delegatedReference = new IdentityReference($delegatedIdentity->identifier(), $delegatedIdentity->type());

        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-report-alpha'),
            identity: $primaryIdentity,
            reference: $primaryReference,
            method: 'password',
            issuedAt: $seedNow - 60,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_report_alpha',
                'session_device_reference' => 'devref_report_alpha',
                'session_device_trust_state' => 'unknown',
                'session_client_platform' => 'Windows',
                'session_device_kind' => 'desktop',
                'session_label' => 'Primary workstation',
                'session_last_activity_at' => $seedNow - 15,
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-report-beta'),
            identity: $primaryIdentity,
            reference: $primaryReference,
            method: 'password',
            issuedAt: $seedNow - 50,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_report_beta',
                'session_device_reference' => 'devref_report_beta',
                'session_device_trust_state' => 'trusted',
                'trusted_device_public_id' => 'tdv_report_beta',
                'trusted_device_credential_present' => true,
                'session_client_platform' => 'macOS',
                'session_device_kind' => 'desktop',
                'session_label' => 'Trusted laptop',
                'session_last_activity_at' => $seedNow - 10,
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-report-gamma'),
            identity: $secondaryIdentity,
            reference: $secondaryReference,
            method: 'password',
            issuedAt: $seedNow - 40,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_report_gamma',
                'session_device_reference' => 'devref_report_gamma',
                'session_device_trust_state' => 'unknown',
                'session_client_platform' => 'Linux',
                'session_device_kind' => 'desktop',
                'session_label' => 'Ops terminal',
                'session_last_activity_at' => $seedNow - 5,
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-report-delta'),
            identity: $delegatedIdentity,
            reference: $delegatedReference,
            method: 'password',
            issuedAt: $seedNow - 35,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_report_delta',
                'session_device_reference' => 'devref_report_delta',
                'session_device_trust_state' => 'unknown',
                'session_client_platform' => 'Linux',
                'session_device_kind' => 'desktop',
                'session_label' => 'Delegated support terminal',
                'session_last_activity_at' => $seedNow - 4,
            ],
        ));

        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_report_beta'),
            reference: $primaryReference,
            deviceReference: 'devref_report_beta',
            issuedAt: $seedNow - 70,
            expiresAt: $seedNow + 600,
            lastUsedAt: $seedNow - 8,
            attributes: [
                'label' => 'Trusted laptop',
                'client_platform' => 'macOS',
                'device_kind' => 'desktop',
            ],
        ));
    }

    private function seedRejectedGovernedActor(Application $app, int $seedNow): void
    {
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);

        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('704'),
            type: 'user',
            attributes: [
                'name' => 'Report Only Admin',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => [
                    'security_center_export',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        );
        $reference = new IdentityReference($identity->identifier(), $identity->type());

        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-report-epsilon'),
            identity: $identity,
            reference: $reference,
            method: 'password',
            issuedAt: $seedNow - 30,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_report_epsilon',
                'session_device_reference' => 'devref_report_epsilon',
                'session_device_trust_state' => 'unknown',
                'session_client_platform' => 'Linux',
                'session_device_kind' => 'desktop',
                'session_label' => 'Report only admin terminal',
                'session_last_activity_at' => $seedNow - 3,
            ],
        ));
    }

    private function seedLongitudinalAuditTrail(string $path, int $seedNow): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $events = [
            [
                'event' => 'security_center_device_revocation_planned',
                'occurred_at' => $seedNow - 3200,
                'correlation_id' => 'audit-corr-1',
                'operation_id' => 'audit-op-1',
                'result' => 'dry_run',
                'operational_context' => [
                    'store_topology' => 'shared_file_store_candidate',
                    'store_fingerprint' => 'fingerprint-a',
                ],
                'administrative_metrics' => [
                    'requested_scope' => 'all',
                    'actor_scope_profile' => 'full',
                    'actor_authorization_mode' => 'direct_admin',
                    'affected_total_resources' => 2,
                ],
                'summary' => [
                    'revoked_sessions' => 1,
                    'revoked_trusted_devices' => 1,
                ],
            ],
            [
                'event' => 'security_center_device_revocation_executed',
                'occurred_at' => $seedNow - 800,
                'correlation_id' => 'audit-corr-2',
                'operation_id' => 'audit-op-2',
                'result' => 'executed',
                'operational_context' => [
                    'store_topology' => 'shared_file_store_candidate',
                    'store_fingerprint' => 'fingerprint-a',
                ],
                'administrative_metrics' => [
                    'requested_scope' => 'sessions',
                    'actor_scope_profile' => 'sessions_only',
                    'actor_authorization_mode' => 'delegated_admin',
                    'affected_total_resources' => 1,
                ],
                'summary' => [
                    'revoked_sessions' => 1,
                    'revoked_trusted_devices' => 0,
                ],
            ],
            [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $seedNow - 5,
                'correlation_id' => 'audit-corr-3',
                'operation_id' => 'audit-op-3',
                'result' => 'authorization_failed',
                'operational_context' => [
                    'store_topology' => 'mixed_driver_topology',
                    'store_fingerprint' => 'fingerprint-b',
                ],
                'administrative_metrics' => [
                    'requested_scope' => 'trusted-devices',
                    'actor_scope_profile' => 'none',
                    'actor_authorization_mode' => null,
                    'affected_total_resources' => 0,
                ],
            ],
        ];

        file_put_contents(
            $path,
            implode(PHP_EOL, array_map(
                static fn (array $event): string => (string) json_encode($event, JSON_THROW_ON_ERROR),
                $events,
            )) . PHP_EOL,
        );
    }

    private function seedLongitudinalStoreDropoutAuditTrail(string $path, int $seedNow): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $events = [
            [
                'event' => 'security_center_device_revocation_executed',
                'occurred_at' => $seedNow - 5000,
                'correlation_id' => 'dropout-corr-1',
                'operation_id' => 'dropout-op-1',
                'result' => 'executed',
                'operational_context' => [
                    'store_topology' => 'shared_file_store_candidate',
                    'store_fingerprint' => 'fingerprint-a',
                ],
                'administrative_metrics' => [
                    'requested_scope' => 'all',
                    'actor_scope_profile' => 'full',
                    'actor_authorization_mode' => 'direct_admin',
                    'affected_total_resources' => 2,
                ],
                'summary' => [
                    'revoked_sessions' => 2,
                    'revoked_trusted_devices' => 0,
                ],
            ],
            [
                'event' => 'security_center_device_revocation_executed',
                'occurred_at' => $seedNow - 60,
                'correlation_id' => 'dropout-corr-2',
                'operation_id' => 'dropout-op-2',
                'result' => 'executed',
                'operational_context' => [
                    'store_topology' => 'mixed_driver_topology',
                    'store_fingerprint' => 'fingerprint-b',
                ],
                'administrative_metrics' => [
                    'requested_scope' => 'sessions',
                    'actor_scope_profile' => 'sessions_only',
                    'actor_authorization_mode' => 'delegated_admin',
                    'affected_total_resources' => 1,
                ],
                'summary' => [
                    'revoked_sessions' => 1,
                    'revoked_trusted_devices' => 0,
                ],
            ],
        ];

        file_put_contents(
            $path,
            implode(PHP_EOL, array_map(
                static fn (array $event): string => (string) json_encode($event, JSON_THROW_ON_ERROR),
                $events,
            )) . PHP_EOL,
        );
    }

    private function bootstrappedApplication(): Application
    {
        $app = require $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        self::assertInstanceOf(Application::class, $app);

        return $app;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readExportEvents(string $path): array
    {
        self::assertFileExists($path);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        ));
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}
