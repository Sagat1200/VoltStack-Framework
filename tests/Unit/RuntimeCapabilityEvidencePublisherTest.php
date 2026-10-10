<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Compilation\BuildManifest;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidencePublisher;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidenceStoreResolver;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationCheck;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationReport;

final class RuntimeCapabilityEvidencePublisherTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-evidence-publisher-' . uniqid('', true);

        mkdir($this->storageRoot . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->storageRoot . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->storageRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Runtime Evidence Publisher',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $this->storageRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => 'frankenphp',
];
PHP
        );

        $escapedBasePath = var_export($this->storageRoot, true);

        file_put_contents(
            $this->storageRoot . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();

\$app->boot();

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function test_publisher_refuses_invalid_verification_report(): void
    {
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'windows-frankenphp-dev',
            profile: 'release',
            checks: [
                new RuntimeCapabilityVerificationCheck(id: 'loop.native.boot', description: 'Arranca el loop nativo', passed: false),
                new RuntimeCapabilityVerificationCheck(id: 'http.native.status200', description: 'Responde 200 OK', passed: true),
            ],
        );

        $publisher = new RuntimeCapabilityEvidencePublisher($this->storageRoot);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the verification report is not valid');

        $publisher->publish($report, evidenceBaseDirectory: $this->storageRoot);
    }

    public function test_publisher_promotes_report_to_native_verified_when_native_checks_pass(): void
    {
        $executedAt = time() - 180;
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'windows-frankenphp-dev',
            profile: 'release',
            executedAt: $executedAt,
            checks: [
                new RuntimeCapabilityVerificationCheck(
                    id: 'loop.native.boot',
                    description: 'FrankenPHP arranca su loop sin modo fallback',
                    passed: true,
                    durationMs: 12.5,
                    notes: ['Detectado FrankenPHP worker mode via frankenphp_is_worker().'],
                ),
                new RuntimeCapabilityVerificationCheck(
                    id: 'http.native.status200',
                    description: 'HTTP nativo responde 200 para /health',
                    passed: true,
                    durationMs: 3.25,
                ),
                new RuntimeCapabilityVerificationCheck(
                    id: 'worker.drain.signal',
                    description: 'SIGTERM trigger de drain sobre worker',
                    passed: true,
                    durationMs: 20.0,
                    notes: ['Graceful shutdown completado en menos de 3s.'],
                ),
            ],
            metadata: [
                'php_version' => '8.4.3',
                'frankenphp_version' => '1.3.0',
            ],
        );

        self::assertTrue($report->valid());
        self::assertTrue($report->provesNativeIntegration());
        self::assertSame('native-verified', $report->evidenceLevel());

        $app = new Application($this->storageRoot);
        $store = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver(
            app: $app,
            driver: 'frankenphp',
            baseDirectory: $this->storageRoot,
        );

        $publisher = new RuntimeCapabilityEvidencePublisher($this->storageRoot);
        $artifact = $publisher->publish($report, evidenceBaseDirectory: $this->storageRoot);
        $store->activateGeneration($artifact->generationId());
        $current = $store->currentArtifact();

        self::assertNotNull($current);
        self::assertSame($artifact->generationId(), $current->generationId());
        self::assertSame('frankenphp', $current->driver());
        self::assertSame('windows-frankenphp-dev', $current->platform());
        self::assertSame('native-verified', $current->capabilities()->evidenceLevel());
        self::assertTrue($current->capabilities()->nativeIntegrationVerified());
        self::assertTrue($current->capabilities()->persistent());
        self::assertTrue($current->capabilities()->nativeHttp());
        self::assertTrue($current->capabilities()->drainControl());
        self::assertFalse($current->capabilities()->concurrent());
        self::assertFalse($current->capabilities()->streaming());
    }

    public function test_publisher_reports_contractual_level_when_no_native_family_checks_pass(): void
    {
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'linux-frankenphp-build',
            profile: 'release',
            checks: [
                new RuntimeCapabilityVerificationCheck(id: 'boot.config.loaded', description: 'Configuracion cargada', passed: true),
                new RuntimeCapabilityVerificationCheck(id: 'boot.providers.registered', description: 'Providers registrados', passed: true),
                new RuntimeCapabilityVerificationCheck(id: 'runtime.status.healthy', description: 'Healthy check', passed: true),
            ],
        );

        self::assertTrue($report->valid());
        self::assertFalse($report->provesNativeIntegration());
        self::assertSame('contractual', $report->evidenceLevel());
    }

    public function test_report_from_array_round_trip_is_consistent(): void
    {
        $executedAt = time() - 60;
        $original = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'linux-prod',
            profile: 'release',
            executedAt: $executedAt,
            checks: [
                new RuntimeCapabilityVerificationCheck(
                    id: 'loop.native.boot',
                    description: 'Arranca loop nativo',
                    passed: true,
                    durationMs: 8.0,
                    notes: ['foo'],
                    metadata: ['bar' => 'baz'],
                ),
            ],
            metadata: ['a' => 'b'],
        );

        $restored = RuntimeCapabilityVerificationReport::fromArray($original->toArray());

        self::assertSame($original->driver(), $restored->driver());
        self::assertSame($original->platform(), $restored->platform());
        self::assertSame($original->profile(), $restored->profile());
        self::assertSame($original->executedAt(), $restored->executedAt());
        self::assertSame($original->totalChecks(), $restored->totalChecks());
        self::assertSame($original->passedChecks(), $restored->passedChecks());
        self::assertSame($original->valid(), $restored->valid());
        self::assertSame($original->provesNativeIntegration(), $restored->provesNativeIntegration());
        self::assertSame($original->evidenceLevel(), $restored->evidenceLevel());
        self::assertSame($original->metadata(), $restored->metadata());
        self::assertSame('loop.native.boot', $restored->checks()[0]->id());
        self::assertSame('foo', $restored->checks()[0]->notes()[0]);
        self::assertSame(['bar' => 'baz'], $restored->checks()[0]->metadata());
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
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($target) || is_link($target)) {
                @unlink($target);
                continue;
            }

            $this->deleteDirectory($target);
        }

        @rmdir($path);
    }
}
