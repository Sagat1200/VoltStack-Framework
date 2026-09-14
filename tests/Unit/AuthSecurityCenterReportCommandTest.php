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
        self::assertStringNotContainsString('Detalle para', $output->stdout());
        self::assertStringNotContainsString('session_public_ids=', $output->stdout());
        self::assertStringNotContainsString('trusted_device_public_id=', $output->stdout());
        self::assertStringNotContainsString('Actores administrativos gobernados:', $output->stdout());
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
        self::assertSame('701', $payload['filters']['identity'] ?? null);
        self::assertSame('user', $payload['filters']['type'] ?? null);
        self::assertTrue((bool) ($payload['filters']['include_public_ids'] ?? false));
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
                '--export-log=' . $exportLogPath,
            ]),
            $output,
        );

        $events = $this->readExportEvents($exportLogPath);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Snapshot exportado en: ' . $exportLogPath, $output->stdout());
        self::assertCount(1, $events);
        self::assertSame('security_center_report_exported', $events[0]['event'] ?? null);
        self::assertSame('exported', $events[0]['result'] ?? null);
        self::assertSame(4, $events[0]['report']['summary']['active_sessions'] ?? null);
        self::assertSame(4, $events[0]['report']['summary']['aggregated_devices'] ?? null);
        self::assertArrayNotHasKey('devices', $events[0]['report']);
        self::assertArrayNotHasKey('management_actors', $events[0]['report']);
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
