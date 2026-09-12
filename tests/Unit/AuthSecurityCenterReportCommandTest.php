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
        self::assertStringContainsString('Sesiones activas: 3', $output->stdout());
        self::assertStringContainsString('Trusted devices activos: 1', $output->stdout());
        self::assertStringContainsString('Identidades unicas: 2', $output->stdout());
        self::assertStringContainsString('Dispositivos agregados: 3', $output->stdout());
        self::assertStringContainsString('Agregados trusted: 1', $output->stdout());
        self::assertStringContainsString('Agregados de management elevado: 1', $output->stdout());
        self::assertStringNotContainsString('Detalle para', $output->stdout());
        self::assertStringNotContainsString('session_public_ids=', $output->stdout());
        self::assertStringNotContainsString('trusted_device_public_id=', $output->stdout());
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
        self::assertSame('devref_report_alpha', $standard['device_reference'] ?? null);
        self::assertSame(['sess_pub_report_alpha'], $standard['session_public_ids'] ?? []);
        self::assertNull($standard['trusted_device_public_id'] ?? null);

        self::assertSame('trusted_device_management', $elevated['management_reason_code'] ?? null);
        self::assertSame('devref_report_beta', $elevated['device_reference'] ?? null);
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
            attributes: ['name' => 'Secondary Security User'],
        );
        $secondaryReference = new IdentityReference($secondaryIdentity->identifier(), $secondaryIdentity->type());

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

    private function bootstrappedApplication(): Application
    {
        $app = require $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        self::assertInstanceOf(Application::class, $app);

        return $app;
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
