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
use Quantum\Auth\Sessions\AuthenticationSessionRecoveryReason;
use Quantum\Console\Commands\AuthSecurityCenterRevokeDeviceCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthSecurityCenterRevokeDeviceCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-auth-security-center-revoke-' . uniqid('', true);

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

    public function test_it_reports_device_revocation_without_persisting_it_in_dry_run(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center.jsonl';

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_alpha',
                '--actor-identity=901',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_ops_admin',
                '--correlation-id=revoke-dry-run-corr',
                '--include-public-ids',
                '--audit-log=' . $auditLogPath,
                '--dry-run',
                '--verbose',
            ]),
            $output,
        );

        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(0, $exitCode);
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha'));
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha-peer'));
        self::assertInstanceOf(TrustedDevice::class, $trustedDevices->find('tdv_admin_alpha'));
        self::assertStringContainsString('Revocacion operacional calculada correctamente (dry-run).', $output->stdout());
        self::assertStringContainsString('Sesiones objetivo: 2', $output->stdout());
        self::assertStringContainsString('Trusted devices objetivo: 1', $output->stdout());
        self::assertStringContainsString('Sesiones revocadas: 2', $output->stdout());
        self::assertStringContainsString('Trusted devices revocados: 1', $output->stdout());
        self::assertStringContainsString('Actor autorizado: user:901 | authority=administrative_actor | privilege=privileged_admin | mode=direct_admin | scopes=security_center_export,admin_device_management', $output->stdout());
        self::assertStringContainsString('Correlation id: revoke-dry-run-corr', $output->stdout());
        self::assertStringContainsString('Operation id: security-center-revoke-device-', $output->stdout());
        self::assertStringContainsString('Metricas administrativas: outcome=authorized profile=full matched=3 affected=3', $output->stdout());
        self::assertStringContainsString('Topology: shared_file_store_candidate | fingerprint=', $output->stdout());
        self::assertStringContainsString(
            'framework/auth/sessions',
            str_replace('\\', '/', $output->stdout()),
        );
        self::assertStringContainsString('session_public_ids=sess_pub_admin_alpha,sess_pub_admin_alpha_peer', $output->stdout());
        self::assertStringContainsString('trusted_device_public_ids=tdv_admin_alpha', $output->stdout());

        $events = $this->readAuditEvents($auditLogPath);
        self::assertCount(1, $events);
        self::assertSame('security_center_device_revocation_planned', $events[0]['event'] ?? null);
        self::assertSame('revoke-dry-run-corr', $events[0]['correlation_id'] ?? null);
        self::assertIsString($events[0]['operation_id'] ?? null);
        self::assertStringStartsWith('security-center-revoke-device-', (string) ($events[0]['operation_id'] ?? ''));
        self::assertSame('dry_run', $events[0]['result'] ?? null);
        self::assertSame('authorized', $events[0]['administrative_metrics']['authorization_outcome'] ?? null);
        self::assertSame('full', $events[0]['administrative_metrics']['actor_scope_profile'] ?? null);
        self::assertSame(3, $events[0]['administrative_metrics']['matched_total_resources'] ?? null);
        self::assertSame(3, $events[0]['administrative_metrics']['affected_total_resources'] ?? null);
        self::assertTrue((bool) ($events[0]['actor']['management_authorized'] ?? false));
        self::assertSame('direct_admin', $events[0]['actor']['management_authorization_mode'] ?? null);
        self::assertNull($events[0]['actor']['management_authorization_reason_code'] ?? null);
        self::assertSame('shared_file_store_candidate', $events[0]['operational_context']['store_topology'] ?? null);
        self::assertSame('file', $events[0]['operational_context']['session_driver'] ?? null);
        self::assertSame('file', $events[0]['operational_context']['trusted_device_driver'] ?? null);
        self::assertSame('801', $events[0]['target']['identity'] ?? null);
        self::assertSame(2, $events[0]['summary']['revoked_sessions'] ?? null);
        self::assertSame(1, $events[0]['summary']['revoked_trusted_devices'] ?? null);
    }

    public function test_it_revokes_sessions_and_trusted_devices_for_an_aggregated_device(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center.jsonl';

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_alpha',
                '--actor-identity=901',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_ops_admin',
                '--include-public-ids',
                '--audit-log=' . $auditLogPath,
            ]),
            $output,
        );

        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(0, $exitCode);
        self::assertNull($sessions->find('session-admin-alpha'));
        self::assertNull($sessions->find('session-admin-alpha-peer'));
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-beta'));
        self::assertSame(
            AuthenticationSessionRecoveryReason::Revoked,
            $sessions->findRecoveryReason('session-admin-alpha'),
        );
        self::assertSame(
            AuthenticationSessionRecoveryReason::Revoked,
            $sessions->findRecoveryReason('session-admin-alpha-peer'),
        );
        self::assertNull($trustedDevices->find('tdv_admin_alpha'));
        self::assertInstanceOf(TrustedDevice::class, $trustedDevices->find('tdv_admin_beta'));
        self::assertStringContainsString('Revocacion operacional ejecutada correctamente.', $output->stdout());

        $events = $this->readAuditEvents($auditLogPath);
        self::assertCount(1, $events);
        self::assertSame('security_center_device_revocation_executed', $events[0]['event'] ?? null);
        self::assertSame('executed', $events[0]['result'] ?? null);
        self::assertSame('privileged_admin', $events[0]['actor']['management_privilege_level'] ?? null);
        self::assertSame('devref_admin_alpha', $events[0]['target']['device_reference'] ?? null);
    }

    public function test_it_can_limit_the_revocation_scope_to_trusted_devices(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_alpha',
                '--actor-identity=901',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_ops_admin',
                '--correlation-id=revoke-json-corr',
                '--scope=trusted-devices',
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(0, $exitCode);
        self::assertSame('revoke-json-corr', $payload['correlation_id'] ?? null);
        self::assertIsString($payload['operation_id'] ?? null);
        self::assertStringStartsWith('security-center-revoke-device-', (string) ($payload['operation_id'] ?? ''));
        self::assertSame('authorized', $payload['administrative_metrics']['authorization_outcome'] ?? null);
        self::assertSame('full', $payload['administrative_metrics']['actor_scope_profile'] ?? null);
        self::assertSame(3, $payload['administrative_metrics']['matched_total_resources'] ?? null);
        self::assertSame(1, $payload['administrative_metrics']['affected_total_resources'] ?? null);
        self::assertSame(['trusted-devices'], $payload['administrative_metrics']['affected_resource_kinds'] ?? null);
        self::assertSame('trusted-devices', $payload['filters']['scope'] ?? null);
        self::assertSame('901', $payload['actor']['identity'] ?? null);
        self::assertSame('administrative_actor', $payload['actor']['management_authority'] ?? null);
        self::assertSame('privileged_admin', $payload['actor']['management_privilege_level'] ?? null);
        self::assertTrue((bool) ($payload['actor']['management_authorized'] ?? false));
        self::assertSame('direct_admin', $payload['actor']['management_authorization_mode'] ?? null);
        self::assertNull($payload['actor']['management_authorization_reason_code'] ?? null);
        self::assertSame('shared_file_store_candidate', $payload['operational_context']['store_topology'] ?? null);
        self::assertSame('file', $payload['operational_context']['session_driver'] ?? null);
        self::assertSame('file', $payload['operational_context']['trusted_device_driver'] ?? null);
        self::assertSame(2, $payload['summary']['matched_sessions'] ?? null);
        self::assertSame(1, $payload['summary']['matched_trusted_devices'] ?? null);
        self::assertSame(0, $payload['summary']['revoked_sessions'] ?? null);
        self::assertSame(1, $payload['summary']['revoked_trusted_devices'] ?? null);
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha'));
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha-peer'));
        self::assertNull($trustedDevices->find('tdv_admin_alpha'));
    }

    public function test_it_allows_a_governed_delegated_actor_to_revoke_an_aggregated_device(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_beta',
                '--actor-identity=902',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_support_admin',
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(0, $exitCode);
        self::assertSame('902', $payload['actor']['identity'] ?? null);
        self::assertSame('delegated_support', $payload['actor']['management_privilege_level'] ?? null);
        self::assertTrue((bool) ($payload['actor']['management_authorized'] ?? false));
        self::assertSame('delegated_admin', $payload['actor']['management_authorization_mode'] ?? null);
        self::assertNull($payload['actor']['management_authorization_reason_code'] ?? null);
        self::assertSame(1, $payload['summary']['matched_sessions'] ?? null);
        self::assertSame(1, $payload['summary']['matched_trusted_devices'] ?? null);
        self::assertSame(1, $payload['summary']['revoked_sessions'] ?? null);
        self::assertSame(1, $payload['summary']['revoked_trusted_devices'] ?? null);
        self::assertNull($sessions->find('session-admin-beta'));
        self::assertNull($trustedDevices->find('tdv_admin_beta'));
    }

    public function test_it_allows_remote_revocation_when_distributed_guard_only_requires_monitoring(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogSource = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-longitudinal.jsonl';
        $this->seedLongitudinalAuditTrail($auditLogSource, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_alpha',
                '--actor-identity=901',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_ops_admin',
                '--audit-log-source=' . $auditLogSource,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(0, $exitCode);
        self::assertTrue((bool) ($payload['distributed_guard']['evaluated'] ?? false));
        self::assertSame($auditLogSource, $payload['distributed_guard']['audit_log_source'] ?? null);
        self::assertSame('recent_lag', $payload['distributed_guard']['activity_drift']['drift_profile'] ?? null);
        self::assertSame('monitor_recent_lag', $payload['distributed_guard']['activity_drift']['recommended_action'] ?? null);
        self::assertSame('observe_recent_lag', $payload['distributed_guard']['operational_response']['response_mode'] ?? null);
        self::assertFalse($payload['distributed_guard']['operational_response']['should_deny_remote_mutations'] ?? true);
        self::assertSame('distributed_recent_lag_monitor', $payload['distributed_guard']['operational_response']['remote_mutation_denial_reason_code'] ?? null);
        self::assertSame('allow_all', $payload['distributed_guard']['operational_response']['remote_mutation_scope_policy'] ?? null);
        self::assertSame(['all', 'sessions', 'trusted-devices'], $payload['distributed_guard']['operational_response']['allowed_remote_mutation_scopes'] ?? null);
        self::assertSame([], $payload['distributed_guard']['operational_response']['denied_remote_mutation_scopes'] ?? null);
        self::assertFalse($payload['distributed_guard_scope_decision']['should_deny'] ?? true);
        self::assertSame('allow_all', $payload['distributed_guard_scope_decision']['scope_policy'] ?? null);
        self::assertSame(['all', 'sessions', 'trusted-devices'], $payload['distributed_guard_scope_decision']['allowed_scopes'] ?? null);
        self::assertSame([], $payload['distributed_guard_scope_decision']['denied_scopes'] ?? null);
        self::assertNull($sessions->find('session-admin-alpha'));
        self::assertNull($sessions->find('session-admin-alpha-peer'));
        self::assertNull($trustedDevices->find('tdv_admin_alpha'));
    }

    public function test_it_allows_session_scope_when_distributed_guard_is_partial_visibility(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogSource = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-partial-visibility.jsonl';
        $this->seedLongitudinalPartialVisibilityAuditTrail($auditLogSource, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_alpha',
                '--actor-identity=901',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_ops_admin',
                '--scope=sessions',
                '--audit-log-source=' . $auditLogSource,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(0, $exitCode);
        self::assertSame('partial_visibility', $payload['distributed_guard']['activity_drift']['drift_profile'] ?? null);
        self::assertSame('sessions_only', $payload['distributed_guard']['operational_response']['remote_mutation_scope_policy'] ?? null);
        self::assertTrue($payload['distributed_guard']['operational_response']['should_deny_remote_mutations'] ?? false);
        self::assertFalse($payload['distributed_guard_scope_decision']['should_deny'] ?? true);
        self::assertSame('sessions', $payload['distributed_guard_scope_decision']['scope'] ?? null);
        self::assertSame('session_revocation', $payload['distributed_guard_scope_decision']['mutation_kind'] ?? null);
        self::assertSame('direct_admin', $payload['distributed_guard_scope_decision']['authorization_mode'] ?? null);
        self::assertSame('privileged_admin', $payload['distributed_guard_scope_decision']['actor_privilege_level'] ?? null);
        self::assertSame('direct_administrative_target', $payload['distributed_guard_scope_decision']['actor_target_relation'] ?? null);
        self::assertSame('sessions_only', $payload['distributed_guard_scope_decision']['scope_policy'] ?? null);
        self::assertSame('actor_target_relation_scope_policy', $payload['distributed_guard_scope_decision']['policy_source'] ?? null);
        self::assertSame('distributed_partial_visibility_guard_privileged_admin_direct_target_policy', $payload['distributed_guard_scope_decision']['policy_reason_code'] ?? null);
        self::assertSame(['sessions'], $payload['distributed_guard_scope_decision']['allowed_scopes'] ?? null);
        self::assertSame(['all', 'trusted-devices'], $payload['distributed_guard_scope_decision']['denied_scopes'] ?? null);
        self::assertNull($sessions->find('session-admin-alpha'));
        self::assertNull($sessions->find('session-admin-alpha-peer'));
        self::assertInstanceOf(TrustedDevice::class, $trustedDevices->find('tdv_admin_alpha'));
    }

    public function test_it_rejects_session_scope_when_distributed_guard_is_partial_visibility_for_delegated_actor(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-partial-delegated-guard.jsonl';
        $auditLogSource = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-partial-visibility.jsonl';
        $this->seedLongitudinalPartialVisibilityAuditTrail($auditLogSource, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_alpha',
                '--actor-identity=902',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_support_admin',
                '--scope=sessions',
                '--audit-log=' . $auditLogPath,
                '--audit-log-source=' . $auditLogSource,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(1, $exitCode);
        self::assertSame('distributed_partial_visibility_guard_delegated_support_delegated_target_sessions_scope', $payload['reason_code'] ?? null);
        self::assertSame('partial_visibility', $payload['distributed_guard']['activity_drift']['drift_profile'] ?? null);
        self::assertSame('sessions_only', $payload['distributed_guard']['operational_response']['remote_mutation_scope_policy'] ?? null);
        self::assertTrue($payload['distributed_guard_scope_decision']['should_deny'] ?? false);
        self::assertSame('sessions', $payload['distributed_guard_scope_decision']['scope'] ?? null);
        self::assertSame('session_revocation', $payload['distributed_guard_scope_decision']['mutation_kind'] ?? null);
        self::assertSame('delegated_admin', $payload['distributed_guard_scope_decision']['authorization_mode'] ?? null);
        self::assertSame('delegated_support', $payload['distributed_guard_scope_decision']['actor_privilege_level'] ?? null);
        self::assertSame('delegated_administrative_target', $payload['distributed_guard_scope_decision']['actor_target_relation'] ?? null);
        self::assertSame('deny_all', $payload['distributed_guard_scope_decision']['scope_policy'] ?? null);
        self::assertSame('actor_target_relation_scope_policy', $payload['distributed_guard_scope_decision']['policy_source'] ?? null);
        self::assertSame('distributed_partial_visibility_guard_delegated_support_delegated_target_policy', $payload['distributed_guard_scope_decision']['policy_reason_code'] ?? null);
        self::assertSame([], $payload['distributed_guard_scope_decision']['allowed_scopes'] ?? null);
        self::assertSame(['all', 'sessions', 'trusted-devices'], $payload['distributed_guard_scope_decision']['denied_scopes'] ?? null);
        self::assertSame(
            'distributed_partial_visibility_guard_delegated_support_delegated_target_sessions_scope',
            $payload['distributed_guard_scope_decision']['reason_code'] ?? null,
        );
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha'));
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha-peer'));
        self::assertInstanceOf(TrustedDevice::class, $trustedDevices->find('tdv_admin_alpha'));

        $events = $this->readAuditEvents($auditLogPath);
        self::assertCount(1, $events);
        self::assertSame('security_center_device_revocation_rejected', $events[0]['event'] ?? null);
        self::assertSame('distributed_guard_denied', $events[0]['result'] ?? null);
        self::assertSame('distributed_partial_visibility_guard_delegated_support_delegated_target_sessions_scope', $events[0]['reason_code'] ?? null);
        self::assertSame('distributed_partial_visibility_guard_delegated_support_delegated_target_policy', $events[0]['distributed_guard_scope_decision']['policy_reason_code'] ?? null);
        self::assertSame('delegated_admin', $events[0]['distributed_guard_scope_decision']['authorization_mode'] ?? null);
    }

    public function test_it_allows_trusted_device_scope_for_self_governed_privileged_admin_under_partial_visibility(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogSource = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-partial-visibility.jsonl';
        $this->seedLongitudinalPartialVisibilityAuditTrail($auditLogSource, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=901',
                '--type=user',
                '--device-reference=devref_ops_admin',
                '--actor-identity=901',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_ops_admin',
                '--scope=trusted-devices',
                '--audit-log-source=' . $auditLogSource,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(0, $exitCode);
        self::assertSame('partial_visibility', $payload['distributed_guard']['activity_drift']['drift_profile'] ?? null);
        self::assertFalse($payload['distributed_guard_scope_decision']['should_deny'] ?? true);
        self::assertSame('trusted-devices', $payload['distributed_guard_scope_decision']['scope'] ?? null);
        self::assertSame('direct_admin', $payload['distributed_guard_scope_decision']['authorization_mode'] ?? null);
        self::assertSame('privileged_admin', $payload['distributed_guard_scope_decision']['actor_privilege_level'] ?? null);
        self::assertSame('self_governed', $payload['distributed_guard_scope_decision']['actor_target_relation'] ?? null);
        self::assertSame('allow_all', $payload['distributed_guard_scope_decision']['scope_policy'] ?? null);
        self::assertSame('actor_target_relation_scope_policy', $payload['distributed_guard_scope_decision']['policy_source'] ?? null);
        self::assertSame(
            'distributed_partial_visibility_guard_privileged_admin_self_governed_policy',
            $payload['distributed_guard_scope_decision']['policy_reason_code'] ?? null,
        );
        self::assertSame(['all', 'sessions', 'trusted-devices'], $payload['distributed_guard_scope_decision']['allowed_scopes'] ?? null);
        self::assertSame([], $payload['distributed_guard_scope_decision']['denied_scopes'] ?? null);
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-ops-admin'));
        self::assertNull($trustedDevices->find('tdv_ops_admin'));
    }

    public function test_it_restricts_self_governed_delegated_admin_to_sessions_under_partial_visibility(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-partial-self-governed-delegated.jsonl';
        $auditLogSource = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-partial-visibility.jsonl';
        $this->seedLongitudinalPartialVisibilityAuditTrail($auditLogSource, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=902',
                '--type=user',
                '--device-reference=devref_support_admin',
                '--actor-identity=902',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_support_admin',
                '--scope=trusted-devices',
                '--audit-log=' . $auditLogPath,
                '--audit-log-source=' . $auditLogSource,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(1, $exitCode);
        self::assertSame(
            'distributed_partial_visibility_guard_delegated_support_self_governed_trusted_devices_scope',
            $payload['reason_code'] ?? null,
        );
        self::assertTrue($payload['distributed_guard_scope_decision']['should_deny'] ?? false);
        self::assertSame('delegated_admin', $payload['distributed_guard_scope_decision']['authorization_mode'] ?? null);
        self::assertSame('delegated_support', $payload['distributed_guard_scope_decision']['actor_privilege_level'] ?? null);
        self::assertSame('self_governed', $payload['distributed_guard_scope_decision']['actor_target_relation'] ?? null);
        self::assertSame('sessions_only', $payload['distributed_guard_scope_decision']['scope_policy'] ?? null);
        self::assertSame('actor_target_relation_scope_policy', $payload['distributed_guard_scope_decision']['policy_source'] ?? null);
        self::assertSame(
            'distributed_partial_visibility_guard_delegated_support_self_governed_policy',
            $payload['distributed_guard_scope_decision']['policy_reason_code'] ?? null,
        );
        self::assertSame(['sessions'], $payload['distributed_guard_scope_decision']['allowed_scopes'] ?? null);
        self::assertSame(['all', 'trusted-devices'], $payload['distributed_guard_scope_decision']['denied_scopes'] ?? null);
        self::assertSame(
            'distributed_partial_visibility_guard_delegated_support_self_governed_trusted_devices_scope',
            $payload['distributed_guard_scope_decision']['reason_code'] ?? null,
        );
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-support-admin'));
        self::assertInstanceOf(TrustedDevice::class, $trustedDevices->find('tdv_support_admin'));

        $events = $this->readAuditEvents($auditLogPath);
        self::assertCount(1, $events);
        self::assertSame('distributed_partial_visibility_guard_delegated_support_self_governed_trusted_devices_scope', $events[0]['reason_code'] ?? null);
        self::assertSame(
            'distributed_partial_visibility_guard_delegated_support_self_governed_policy',
            $events[0]['distributed_guard_scope_decision']['policy_reason_code'] ?? null,
        );
        self::assertSame('self_governed', $events[0]['distributed_guard_scope_decision']['actor_target_relation'] ?? null);
    }

    public function test_it_rejects_scope_when_delegated_actor_lacks_trusted_device_management_permission(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_beta',
                '--actor-identity=903',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_support_sessions',
                '--scope=trusted-devices',
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(1, $exitCode);
        self::assertSame('unauthorized_management_actor', $payload['reason_code'] ?? null);
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-beta'));
        self::assertInstanceOf(TrustedDevice::class, $trustedDevices->find('tdv_admin_beta'));
    }

    public function test_it_rejects_revocation_when_actor_session_is_not_governed_for_admin_device_management(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center.jsonl';

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_alpha',
                '--actor-identity=802',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_other',
                '--audit-log=' . $auditLogPath,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(1, $exitCode);
        self::assertSame('unauthorized_management_actor', $payload['reason_code'] ?? null);
        self::assertSame(
            'El actor administrativo no tiene una sesion gobernada valida para revocar dispositivos agregados.',
            $payload['error'] ?? null,
        );
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha'));
        self::assertInstanceOf(TrustedDevice::class, $trustedDevices->find('tdv_admin_alpha'));

        $events = $this->readAuditEvents($auditLogPath);
        self::assertCount(1, $events);
        self::assertSame('security_center_device_revocation_rejected', $events[0]['event'] ?? null);
        self::assertSame('authorization_failed', $events[0]['result'] ?? null);
        self::assertSame('unauthorized_management_actor', $events[0]['reason_code'] ?? null);
        self::assertSame('authorization_failed', $events[0]['administrative_metrics']['authorization_outcome'] ?? null);
        self::assertSame('none', $events[0]['administrative_metrics']['actor_scope_profile'] ?? null);
        self::assertSame(0, $events[0]['administrative_metrics']['matched_total_resources'] ?? null);
        self::assertSame('802', $events[0]['actor']['identity'] ?? null);
        self::assertSame('not_administrative_actor', $events[0]['actor']['management_authorization_reason_code'] ?? null);
    }

    public function test_it_rejects_remote_revocation_when_distributed_guard_detects_store_dropout(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-guard.jsonl';
        $auditLogSource = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-dropout.jsonl';
        $this->seedLongitudinalStoreDropoutAuditTrail($auditLogSource, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_alpha',
                '--actor-identity=901',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_ops_admin',
                '--audit-log=' . $auditLogPath,
                '--audit-log-source=' . $auditLogSource,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(1, $exitCode);
        self::assertSame('distributed_store_dropout_guard_all_scope', $payload['reason_code'] ?? null);
        self::assertSame(
            'La mutacion remota fue bloqueada por la guardia distribuida del security center.',
            $payload['error'] ?? null,
        );
        self::assertTrue((bool) ($payload['distributed_guard']['evaluated'] ?? false));
        self::assertSame('store_dropout', $payload['distributed_guard']['activity_drift']['drift_profile'] ?? null);
        self::assertSame('investigate_store_dropout', $payload['distributed_guard']['activity_drift']['recommended_action'] ?? null);
        self::assertSame('contain_store_dropout', $payload['distributed_guard']['operational_response']['response_mode'] ?? null);
        self::assertTrue($payload['distributed_guard']['operational_response']['should_deny_remote_mutations'] ?? false);
        self::assertSame('distributed_store_dropout_guard', $payload['distributed_guard']['operational_response']['remote_mutation_denial_reason_code'] ?? null);
        self::assertSame('deny_all', $payload['distributed_guard']['operational_response']['remote_mutation_scope_policy'] ?? null);
        self::assertTrue($payload['distributed_guard_scope_decision']['should_deny'] ?? false);
        self::assertSame('all', $payload['distributed_guard_scope_decision']['scope'] ?? null);
        self::assertSame('distributed_store_dropout_guard_all_scope', $payload['distributed_guard_scope_decision']['reason_code'] ?? null);
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha'));
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha-peer'));
        self::assertInstanceOf(TrustedDevice::class, $trustedDevices->find('tdv_admin_alpha'));

        $events = $this->readAuditEvents($auditLogPath);
        self::assertCount(1, $events);
        self::assertSame('security_center_device_revocation_rejected', $events[0]['event'] ?? null);
        self::assertSame('distributed_guard_denied', $events[0]['result'] ?? null);
        self::assertSame('distributed_store_dropout_guard_all_scope', $events[0]['reason_code'] ?? null);
        self::assertSame('distributed_guard_denied', $events[0]['administrative_metrics']['authorization_outcome'] ?? null);
        self::assertSame('full', $events[0]['administrative_metrics']['actor_scope_profile'] ?? null);
        self::assertSame('contain_store_dropout', $events[0]['distributed_guard']['operational_response']['response_mode'] ?? null);
        self::assertSame('distributed_store_dropout_guard_all_scope', $events[0]['distributed_guard_scope_decision']['reason_code'] ?? null);
    }

    public function test_it_rejects_trusted_device_scope_when_distributed_guard_is_partial_visibility(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedFixtures($app, $seedNow);
        $auditLogPath = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-partial-guard.jsonl';
        $auditLogSource = $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit' . DIRECTORY_SEPARATOR . 'security-center-partial-visibility.jsonl';
        $this->seedLongitudinalPartialVisibilityAuditTrail($auditLogSource, $seedNow);

        $command = new AuthSecurityCenterRevokeDeviceCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:security-center:revoke-device',
                '--identity=801',
                '--type=user',
                '--device-reference=devref_admin_alpha',
                '--actor-identity=901',
                '--actor-type=user',
                '--actor-session-public-id=sess_pub_ops_admin',
                '--scope=trusted-devices',
                '--audit-log=' . $auditLogPath,
                '--audit-log-source=' . $auditLogSource,
                '--json',
            ]),
            $output,
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($output->stdout(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        self::assertSame(1, $exitCode);
        self::assertSame('distributed_partial_visibility_guard_privileged_admin_direct_target_trusted_devices_scope', $payload['reason_code'] ?? null);
        self::assertSame('partial_visibility', $payload['distributed_guard']['activity_drift']['drift_profile'] ?? null);
        self::assertSame('sessions_only', $payload['distributed_guard']['operational_response']['remote_mutation_scope_policy'] ?? null);
        self::assertTrue($payload['distributed_guard_scope_decision']['should_deny'] ?? false);
        self::assertSame('trusted-devices', $payload['distributed_guard_scope_decision']['scope'] ?? null);
        self::assertSame('trusted_device_revocation', $payload['distributed_guard_scope_decision']['mutation_kind'] ?? null);
        self::assertSame('direct_admin', $payload['distributed_guard_scope_decision']['authorization_mode'] ?? null);
        self::assertSame('direct_administrative_target', $payload['distributed_guard_scope_decision']['actor_target_relation'] ?? null);
        self::assertSame('actor_target_relation_scope_policy', $payload['distributed_guard_scope_decision']['policy_source'] ?? null);
        self::assertSame('distributed_partial_visibility_guard_privileged_admin_direct_target_policy', $payload['distributed_guard_scope_decision']['policy_reason_code'] ?? null);
        self::assertSame('distributed_partial_visibility_guard_privileged_admin_direct_target_trusted_devices_scope', $payload['distributed_guard_scope_decision']['reason_code'] ?? null);
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha'));
        self::assertInstanceOf(AuthenticationSession::class, $sessions->find('session-admin-alpha-peer'));
        self::assertInstanceOf(TrustedDevice::class, $trustedDevices->find('tdv_admin_alpha'));

        $events = $this->readAuditEvents($auditLogPath);
        self::assertCount(1, $events);
        self::assertSame('security_center_device_revocation_rejected', $events[0]['event'] ?? null);
        self::assertSame('distributed_guard_denied', $events[0]['result'] ?? null);
        self::assertSame('distributed_partial_visibility_guard_privileged_admin_direct_target_trusted_devices_scope', $events[0]['reason_code'] ?? null);
        self::assertSame('distributed_partial_visibility_guard_privileged_admin_direct_target_policy', $events[0]['distributed_guard_scope_decision']['policy_reason_code'] ?? null);
        self::assertSame('distributed_partial_visibility_guard_privileged_admin_direct_target_trusted_devices_scope', $events[0]['distributed_guard_scope_decision']['reason_code'] ?? null);
    }

    private function seedFixtures(Application $app, int $seedNow): void
    {
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);

        $primaryIdentity = new GenericIdentity(
            identifier: new IdentityIdentifier('801'),
            type: 'user',
            attributes: ['name' => 'Admin Target User'],
        );
        $primaryReference = new IdentityReference($primaryIdentity->identifier(), $primaryIdentity->type());

        $secondaryIdentity = new GenericIdentity(
            identifier: new IdentityIdentifier('802'),
            type: 'user',
            attributes: ['name' => 'Other User'],
        );
        $secondaryReference = new IdentityReference($secondaryIdentity->identifier(), $secondaryIdentity->type());

        $opsAdminIdentity = new GenericIdentity(
            identifier: new IdentityIdentifier('901'),
            type: 'user',
            attributes: [
                'name' => 'Ops Admin',
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
        $opsAdminReference = new IdentityReference($opsAdminIdentity->identifier(), $opsAdminIdentity->type());

        $delegatedSupportIdentity = new GenericIdentity(
            identifier: new IdentityIdentifier('902'),
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
        $delegatedSupportReference = new IdentityReference(
            $delegatedSupportIdentity->identifier(),
            $delegatedSupportIdentity->type(),
        );

        $delegatedSessionsIdentity = new GenericIdentity(
            identifier: new IdentityIdentifier('903'),
            type: 'user',
            attributes: [
                'name' => 'Delegated Sessions Support',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'delegated_session',
                'auth_management_scopes' => [
                    'admin_session_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'delegated_support',
            ],
        );
        $delegatedSessionsReference = new IdentityReference(
            $delegatedSessionsIdentity->identifier(),
            $delegatedSessionsIdentity->type(),
        );

        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-admin-alpha'),
            identity: $primaryIdentity,
            reference: $primaryReference,
            method: 'password',
            issuedAt: $seedNow - 60,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_admin_alpha',
                'session_device_reference' => 'devref_admin_alpha',
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-admin-alpha-peer'),
            identity: $primaryIdentity,
            reference: $primaryReference,
            method: 'password',
            issuedAt: $seedNow - 55,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_admin_alpha_peer',
                'session_device_reference' => 'devref_admin_alpha',
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-admin-beta'),
            identity: $primaryIdentity,
            reference: $primaryReference,
            method: 'password',
            issuedAt: $seedNow - 50,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_admin_beta',
                'session_device_reference' => 'devref_admin_beta',
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-other-user'),
            identity: $secondaryIdentity,
            reference: $secondaryReference,
            method: 'password',
            issuedAt: $seedNow - 45,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_other',
                'session_device_reference' => 'devref_admin_alpha',
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-ops-admin'),
            identity: $opsAdminIdentity,
            reference: $opsAdminReference,
            method: 'password',
            issuedAt: $seedNow - 40,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_ops_admin',
                'session_device_reference' => 'devref_ops_admin',
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-support-admin'),
            identity: $delegatedSupportIdentity,
            reference: $delegatedSupportReference,
            method: 'password',
            issuedAt: $seedNow - 35,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_support_admin',
                'session_device_reference' => 'devref_support_admin',
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-support-sessions'),
            identity: $delegatedSessionsIdentity,
            reference: $delegatedSessionsReference,
            method: 'password',
            issuedAt: $seedNow - 34,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_support_sessions',
                'session_device_reference' => 'devref_support_sessions',
            ],
        ));

        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_admin_alpha'),
            reference: $primaryReference,
            deviceReference: 'devref_admin_alpha',
            issuedAt: $seedNow - 80,
            expiresAt: $seedNow + 600,
            lastUsedAt: $seedNow - 10,
        ));
        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_admin_beta'),
            reference: $primaryReference,
            deviceReference: 'devref_admin_beta',
            issuedAt: $seedNow - 75,
            expiresAt: $seedNow + 600,
            lastUsedAt: $seedNow - 12,
        ));
        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_other'),
            reference: $secondaryReference,
            deviceReference: 'devref_admin_alpha',
            issuedAt: $seedNow - 70,
            expiresAt: $seedNow + 600,
            lastUsedAt: $seedNow - 9,
        ));
        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_ops_admin'),
            reference: $opsAdminReference,
            deviceReference: 'devref_ops_admin',
            issuedAt: $seedNow - 68,
            expiresAt: $seedNow + 600,
            lastUsedAt: $seedNow - 8,
        ));
        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_support_admin'),
            reference: $delegatedSupportReference,
            deviceReference: 'devref_support_admin',
            issuedAt: $seedNow - 66,
            expiresAt: $seedNow + 600,
            lastUsedAt: $seedNow - 7,
        ));
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
    private function readAuditEvents(string $path): array
    {
        self::assertFileExists($path);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
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
                'event' => 'security_center_device_revocation_executed',
                'occurred_at' => $seedNow - 795,
                'correlation_id' => 'corr-1',
                'operation_id' => 'op-1',
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
                    'revoked_sessions' => 1,
                    'revoked_trusted_devices' => 1,
                ],
            ],
            [
                'event' => 'security_center_device_revocation_executed',
                'occurred_at' => $seedNow - 700,
                'correlation_id' => 'corr-2',
                'operation_id' => 'op-2',
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
                'event' => 'security_center_device_revocation_executed',
                'occurred_at' => $seedNow,
                'correlation_id' => 'corr-3',
                'operation_id' => 'op-3',
                'result' => 'executed',
                'operational_context' => [
                    'store_topology' => 'mixed_driver_topology',
                    'store_fingerprint' => 'fingerprint-b',
                ],
                'administrative_metrics' => [
                    'requested_scope' => 'trusted-devices',
                    'actor_scope_profile' => 'trusted_devices_only',
                    'actor_authorization_mode' => 'delegated_admin',
                    'affected_total_resources' => 1,
                ],
                'summary' => [
                    'revoked_sessions' => 0,
                    'revoked_trusted_devices' => 1,
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

    private function seedLongitudinalPartialVisibilityAuditTrail(string $path, int $seedNow): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $events = [
            [
                'event' => 'security_center_device_revocation_executed',
                'occurred_at' => $seedNow - 1200,
                'correlation_id' => 'partial-corr-1',
                'operation_id' => 'partial-op-1',
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
                'correlation_id' => 'partial-corr-2',
                'operation_id' => 'partial-op-2',
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
