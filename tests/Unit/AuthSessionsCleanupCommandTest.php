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
use Quantum\Console\Commands\AuthSessionsCleanupCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthSessionsCleanupCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-auth-cleanup-' . uniqid('', true);

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
\$config->set('auth.session.cleanup.tombstone_retention', 60);

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_cleans_expired_sessions_and_retained_tombstones(): void
    {
        $seedNow = time();
        $app = $this->bootstrappedApplication();
        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('501'),
            type: 'user',
            attributes: ['name' => 'Cleanup User'],
        );

        $repository->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-live'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: $seedNow,
            expiresAt: $seedNow + 600,
            attributes: ['session_public_id' => 'sess_pub_live'],
        ));

        $repository->save(new AuthenticationSession(
            id: new AuthenticationSessionId('session-expired'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: $seedNow - 600,
            expiresAt: $seedNow - 1,
            attributes: ['session_public_id' => 'sess_pub_expired'],
        ));

        $repository->delete('session-old-tombstone', AuthenticationSessionRecoveryReason::Revoked);

        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_live'),
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            deviceReference: 'devref_live',
            issuedAt: $seedNow,
            expiresAt: $seedNow + 600,
            lastUsedAt: $seedNow + 60,
        ));
        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_expired'),
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            deviceReference: 'devref_expired',
            issuedAt: $seedNow - 600,
            expiresAt: $seedNow - 1,
            lastUsedAt: $seedNow - 10,
        ));

        $command = new AuthSessionsCleanupCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'auth:sessions:cleanup',
                '--now=' . ($seedNow + 61),
                '--verbose',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertNotNull($repository->find('session-live'));
        self::assertNull($repository->find('session-expired'));
        self::assertSame(AuthenticationSessionRecoveryReason::Expired, $repository->findRecoveryReason('session-expired'));
        self::assertNull($repository->findRecoveryReason('session-old-tombstone'));
        self::assertNotNull($trustedDevices->find('tdv_live'));
        self::assertNull($trustedDevices->find('tdv_expired'));
        self::assertStringContainsString('Cleanup de Authentication ejecutado correctamente.', $output->stdout());
        self::assertStringContainsString('Sesiones expiradas purgadas: 1', $output->stdout());
        self::assertStringContainsString('Trusted devices expirados purgados: 1', $output->stdout());
        self::assertStringContainsString('Tombstones purgados: 1', $output->stdout());
        self::assertStringContainsString('Driver activo: file', $output->stdout());
        self::assertStringContainsString('Driver trusted devices: file', $output->stdout());
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
