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
use Quantum\Console\Commands\AuthDevicesReconcileCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthDevicesReconcileCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-auth-reconcile-' . uniqid('', true);

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

    public function test_it_reports_reconciliation_changes_without_persisting_them_in_dry_run(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedReconciliationFixtures($app, $seedNow);

        $command = new AuthDevicesReconcileCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:devices:reconcile',
                '--now=' . $seedNow,
                '--dry-run',
                '--verbose',
            ]),
            $output,
        );

        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $promote = $sessions->find('session-promote');
        $demote = $sessions->find('session-demote');

        self::assertSame(0, $exitCode);
        self::assertInstanceOf(AuthenticationSession::class, $promote);
        self::assertInstanceOf(AuthenticationSession::class, $demote);
        self::assertSame('unknown', $promote->attributes['session_device_trust_state'] ?? null);
        self::assertSame('tdv_old', $demote->attributes['trusted_device_public_id'] ?? null);
        self::assertStringContainsString('Reconciliacion de trusted devices calculada correctamente (dry-run).', $output->stdout());
        self::assertStringContainsString('Sesiones reconciliadas: 2', $output->stdout());
        self::assertStringContainsString('Promovidas a trusted: 1', $output->stdout());
        self::assertStringContainsString('Degradadas a unknown: 1', $output->stdout());
        self::assertStringContainsString('Driver sesiones: file', $output->stdout());
        self::assertStringContainsString('Driver trusted devices: file', $output->stdout());
    }

    public function test_it_reconciles_session_trust_state_against_trusted_devices_store(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $this->seedReconciliationFixtures($app, $seedNow);

        $command = new AuthDevicesReconcileCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:devices:reconcile',
                '--now=' . $seedNow,
            ]),
            $output,
        );

        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $promote = $sessions->find('session-promote');
        $demote = $sessions->find('session-demote');
        $stable = $sessions->find('session-stable');
        $expired = $sessions->find('session-expired');

        self::assertSame(0, $exitCode);
        self::assertInstanceOf(AuthenticationSession::class, $promote);
        self::assertInstanceOf(AuthenticationSession::class, $demote);
        self::assertInstanceOf(AuthenticationSession::class, $stable);
        self::assertInstanceOf(AuthenticationSession::class, $expired);

        self::assertSame('trusted', $promote->attributes['session_device_trust_state'] ?? null);
        self::assertSame('tdv_alpha', $promote->attributes['trusted_device_public_id'] ?? null);
        self::assertTrue((bool) ($promote->attributes['trusted_device_credential_present'] ?? false));

        self::assertSame('unknown', $demote->attributes['session_device_trust_state'] ?? null);
        self::assertNull($demote->attributes['trusted_device_public_id'] ?? null);
        self::assertFalse((bool) ($demote->attributes['trusted_device_credential_present'] ?? true));

        self::assertSame('trusted', $stable->attributes['session_device_trust_state'] ?? null);
        self::assertSame('tdv_keep', $stable->attributes['trusted_device_public_id'] ?? null);
        self::assertTrue((bool) ($stable->attributes['trusted_device_credential_present'] ?? false));

        self::assertSame('trusted', $expired->attributes['session_device_trust_state'] ?? null);
        self::assertStringContainsString('Reconciliacion de trusted devices ejecutada correctamente.', $output->stdout());
        self::assertStringContainsString('Sesiones activas escaneadas: 3', $output->stdout());
        self::assertStringContainsString('Sesiones reconciliadas: 2', $output->stdout());
        self::assertStringContainsString('Sesiones expiradas omitidas: 1', $output->stdout());
    }

    private function seedReconciliationFixtures(Application $app, int $seedNow): void
    {
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('601'),
            type: 'user',
            attributes: ['name' => 'Reconcile User'],
        );
        $reference = new IdentityReference($identity->identifier(), $identity->type());

        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-promote'),
            identity: $identity,
            reference: $reference,
            method: 'password',
            issuedAt: $seedNow - 20,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_promote',
                'session_device_reference' => 'devref_alpha',
                'session_device_trust_state' => 'unknown',
                'trusted_device_public_id' => null,
                'trusted_device_credential_present' => false,
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-demote'),
            identity: $identity,
            reference: $reference,
            method: 'password',
            issuedAt: $seedNow - 30,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_demote',
                'session_device_reference' => 'devref_beta',
                'session_device_trust_state' => 'trusted',
                'trusted_device_public_id' => 'tdv_old',
                'trusted_device_credential_present' => true,
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-stable'),
            identity: $identity,
            reference: $reference,
            method: 'password',
            issuedAt: $seedNow - 40,
            expiresAt: $seedNow + 600,
            attributes: [
                'session_public_id' => 'sess_pub_stable',
                'session_device_reference' => 'devref_keep',
                'session_device_trust_state' => 'trusted',
                'trusted_device_public_id' => 'tdv_keep',
                'trusted_device_credential_present' => true,
            ],
        ));
        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-expired'),
            identity: $identity,
            reference: $reference,
            method: 'password',
            issuedAt: $seedNow - 700,
            expiresAt: $seedNow - 1,
            attributes: [
                'session_public_id' => 'sess_pub_expired',
                'session_device_reference' => 'devref_expired',
                'session_device_trust_state' => 'trusted',
                'trusted_device_public_id' => 'tdv_expired',
                'trusted_device_credential_present' => true,
            ],
        ));

        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_alpha'),
            reference: $reference,
            deviceReference: 'devref_alpha',
            issuedAt: $seedNow - 50,
            expiresAt: $seedNow + 600,
            lastUsedAt: $seedNow - 10,
        ));
        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_keep'),
            reference: $reference,
            deviceReference: 'devref_keep',
            issuedAt: $seedNow - 60,
            expiresAt: $seedNow + 600,
            lastUsedAt: $seedNow - 5,
        ));
        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_expired'),
            reference: $reference,
            deviceReference: 'devref_expired',
            issuedAt: $seedNow - 700,
            expiresAt: $seedNow - 1,
            lastUsedAt: $seedNow - 100,
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
