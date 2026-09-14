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
        self::assertStringContainsString('session_public_ids=sess_pub_admin_alpha,sess_pub_admin_alpha_peer', $output->stdout());
        self::assertStringContainsString('trusted_device_public_ids=tdv_admin_alpha', $output->stdout());

        $events = $this->readAuditEvents($auditLogPath);
        self::assertCount(1, $events);
        self::assertSame('security_center_device_revocation_planned', $events[0]['event'] ?? null);
        self::assertSame('dry_run', $events[0]['result'] ?? null);
        self::assertSame('direct_admin', $events[0]['actor']['management_authorization_mode'] ?? null);
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
        self::assertSame('trusted-devices', $payload['filters']['scope'] ?? null);
        self::assertSame('901', $payload['actor']['identity'] ?? null);
        self::assertSame('administrative_actor', $payload['actor']['management_authority'] ?? null);
        self::assertSame('privileged_admin', $payload['actor']['management_privilege_level'] ?? null);
        self::assertSame('direct_admin', $payload['actor']['management_authorization_mode'] ?? null);
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
        self::assertSame('delegated_admin', $payload['actor']['management_authorization_mode'] ?? null);
        self::assertSame(1, $payload['summary']['matched_sessions'] ?? null);
        self::assertSame(1, $payload['summary']['matched_trusted_devices'] ?? null);
        self::assertSame(1, $payload['summary']['revoked_sessions'] ?? null);
        self::assertSame(1, $payload['summary']['revoked_trusted_devices'] ?? null);
        self::assertNull($sessions->find('session-admin-beta'));
        self::assertNull($trustedDevices->find('tdv_admin_beta'));
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
        self::assertSame('802', $events[0]['actor']['identity'] ?? null);
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
