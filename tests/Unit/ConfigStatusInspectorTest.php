<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Config\SecretReference;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Diagnostics\ConfigStatusInspector;
use VoltStack\Framework\Application;

final class ConfigStatusInspectorTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-config-status-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

return [
    'name' => 'VoltStack Status',
    'env' => 'testing',
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            <<<'PHP'
<?php

return [
    'default' => 'sqlite',
    'connections' => [
        'default' => [
            'driver' => 'sqlite',
            'password' => 'local-password',
        ],
    ],
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_application_registers_config_status_inspector_service(): void
    {
        $app = new Application($this->basePath);

        self::assertInstanceOf(ConfigStatusInspector::class, $app->configStatusInspector());
    }

    public function test_status_inspector_reports_redacted_effective_configuration_and_active_generation(): void
    {
        $app = $this->bootConfiguredApplication();
        $report = $app->configStatusInspector()->inspect($app);

        self::assertSame('testing', $report->environment());
        self::assertSame('root', $report->scopeKind());
        self::assertTrue($report->hasActiveGeneration());
        self::assertTrue($report->publishedMatchesEffective());
        self::assertSame([], $report->alerts());
        self::assertGreaterThanOrEqual(2, $report->documentCount());
        self::assertGreaterThanOrEqual(2, $report->provenanceCount());
        self::assertIsArray($report->redactedConfig()['app']['key']);
        self::assertSame('secret_reference', $report->redactedConfig()['app']['key']['_type']);
        self::assertSame('[redacted]', $report->redactedConfig()['app']['key']['value']);
        self::assertSame('[redacted]', $report->redactedConfig()['database']['connections']['default']['password']);
        self::assertContains(
            'app',
            array_map(
                static fn (array $document): ?string => $document['descriptor']['namespace'] ?? null,
                $report->documents(),
            ),
        );
    }

    public function test_status_inspector_detects_tenant_effective_snapshot_drift_from_active_generation(): void
    {
        $app = $this->bootConfiguredApplication();
        $app->enterRequestScope();

        try {
            $app->configWriter()->set('app.name', 'Request Config');
            $app->enterTenantScope();

            try {
                $app->configWriter()->set('app.name', 'Tenant Config');
                $report = $app->configStatusInspector()->inspect($app);

                self::assertSame('tenant', $report->scopeKind());
                self::assertNotNull($report->scopeId());
                self::assertNotNull($report->parentScopeId());
                self::assertTrue($report->hasScopeOverrides());
                self::assertFalse($report->publishedMatchesEffective());
                self::assertSame('Tenant Config', $report->redactedConfig()['app']['name']);
                self::assertSame('Tenant Config', $report->redactedOverrides()['app']['name']);
                self::assertContains(
                    'El snapshot efectivo difiere de la generacion de configuracion activa.',
                    $report->alerts(),
                );
            } finally {
                $app->leaveScope();
            }
        } finally {
            $app->leaveScope();
        }
    }

    private function bootConfiguredApplication(): Application
    {
        $app = new Application($this->basePath);
        $repository = $app->make(ConfigRepository::class);
        $repository->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $repository->set('app.key', new SecretReference('APP_KEY'));

        $codec = $app->configSnapshotCodec();
        $baseSnapshot = $repository->snapshot(provenance: $repository->provenance());
        $publishedSnapshot = $repository->snapshot(
            provenance: $baseSnapshot->provenance(),
            configId: $codec->configId($baseSnapshot),
        );

        $artifact = $app->configManifestStore()->publish($publishedSnapshot);
        $app->configManifestStore()->activateGeneration($artifact->generationId());

        return $app;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $subPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($subPath) || is_link($subPath)) {
                @unlink($subPath);
                continue;
            }

            $this->deleteDirectory($subPath);
        }

        @rmdir($path);
    }
}
