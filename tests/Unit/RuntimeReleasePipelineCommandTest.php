<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\Manifest\BootstrapManifestStore;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Commands\RuntimeReleasePipelineCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Compilation\BuildManifest;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationStoreResolver;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidenceStoreResolver;
use VoltStack\Runtime\RuntimeCapabilities;

final class RuntimeReleasePipelineCommandTest extends TestCase
{
    private string $basePath;

    private string $artifactDirectory;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-release-pipeline-' . uniqid('', true);
        $this->artifactDirectory = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'bootstrap';
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'runtime-release-pipeline.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Runtime Release Pipeline',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => 'frankenphp',
    'smoke_requests' => ['GET:/ok', 'GET:/health'],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'telemetry.php',
            <<<PHP
<?php

declare(strict_types=1);

return [
    'exporter' => 'jsonl',
    'jsonl_path' => {$this->exportValue($this->telemetryPath)},
];
PHP
        );

        $escapedBasePath = var_export($this->basePath, true);
        $driftMarkerPath = var_export(
            $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'config-drift.marker',
            true,
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use Quantum\Http\Request;
use Quantum\Http\Response;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;

\$app = new Application({$escapedBasePath});
\$app->instance(KernelContract::class, new class implements KernelContract {
    public function handle(Request \$request): Response
    {
        return match (\$request->path()) {
            '/ok' => new Response('ok'),
            '/health' => new Response('healthy'),
            default => new Response('missing', 404),
        };
    }

    public function setMiddlewares(array \$middlewares): void
    {
    }

    public function pushMiddleware(callable|string|\\Quantum\\HttpKernel\\Contracts\\MiddlewareInterface \$middleware): void
    {
    }
});
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();

if (is_file({$driftMarkerPath})) {
    \$app->make(\\Quantum\\Config\\ConfigRepository::class)->set('app.name', 'Drifted after bootstrap');
}

\$app->boot();

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_runtime_release_pipeline_command_runs_release_and_smoke_successfully(): void
    {
        $command = new RuntimeReleasePipelineCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:release-pipeline',
                '--requests=GET:/ok,GET:/health',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('runtime:release-pipeline', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame('contractual', $decoded['report']['capability_evidence']['level'] ?? null);
        self::assertSame(false, $decoded['report']['capability_evidence']['native_integration_verified'] ?? null);
        self::assertSame(null, $decoded['report']['failed_stage'] ?? null);
        self::assertSame(true, $decoded['report']['release_check']['passed'] ?? null);
        self::assertSame(true, $decoded['report']['smoke_check']['passed'] ?? null);
        self::assertSame(false, $decoded['report']['rollback']['triggered'] ?? null);
        self::assertSame(false, $decoded['report']['drain']['required'] ?? null);
        self::assertSame('none', $decoded['report']['drain']['action'] ?? null);
        self::assertNotEmpty($decoded['report']['release_check']['generation_id'] ?? null);
    }

    public function test_runtime_release_pipeline_command_rolls_back_bootstrap_when_smoke_check_fails(): void
    {
        $initialGeneration = $this->publishBootstrapGeneration('stable');
        $command = new RuntimeReleasePipelineCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:release-pipeline',
                '--requests=GET:/missing',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['passed'] ?? null);
        self::assertSame('contractual', $decoded['report']['capability_evidence']['level'] ?? null);
        self::assertSame('runtime_smoke', $decoded['report']['failed_stage'] ?? null);
        self::assertSame(true, $decoded['report']['rollback']['triggered'] ?? null);
        self::assertSame(true, $decoded['report']['rollback']['bootstrap']['rolled_back'] ?? null);
        self::assertSame($initialGeneration, $decoded['report']['rollback']['bootstrap']['generation_active'] ?? null);
        self::assertSame(true, $decoded['report']['drain']['required'] ?? null);
        self::assertSame('drain', $decoded['report']['drain']['action'] ?? null);
        self::assertSame('runtime_smoke', $decoded['report']['drain']['failed_stage'] ?? null);

        $current = (new BuildManifest($this->artifactDirectory))->current();
        self::assertNotNull($current);
        self::assertSame($initialGeneration, $current->id);
    }

    public function test_runtime_release_pipeline_command_can_publish_calibration_after_a_successful_run(): void
    {
        $command = new RuntimeReleasePipelineCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:release-pipeline',
                '--requests=GET:/ok',
                '--publish-calibration',
                '--warmup=1',
                '--iterations=2',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame(true, $decoded['report']['calibration']['passed'] ?? null);
        self::assertSame('contractual', $decoded['report']['capability_evidence']['level'] ?? null);
        self::assertSame('frankenphp', $decoded['report']['published_calibration']['driver'] ?? null);
        self::assertSame('published-calibration', $decoded['report']['published_calibration']['recommended_budget']['source'] ?? null);

        $app = new Application($this->basePath);
        $store = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver($app, 'frankenphp');
        $current = $store->currentArtifact();

        self::assertNotNull($current);
        self::assertSame(
            $decoded['report']['published_calibration']['generation_id'] ?? null,
            $current->generationId(),
        );
    }

    public function test_runtime_release_pipeline_command_can_emit_telemetry(): void
    {
        $command = new RuntimeReleasePipelineCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:release-pipeline',
                '--requests=GET:/missing',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"runtime_release_pipeline"', $telemetry);
        self::assertStringContainsString('"capability_evidence_level":"contractual"', $telemetry);
        self::assertStringContainsString('"native_integration_verified":false', $telemetry);
        self::assertStringContainsString('"drain_required":true', $telemetry);
        self::assertStringContainsString('"drain_action":"drain"', $telemetry);
    }

    public function test_runtime_release_pipeline_command_uses_active_capability_evidence_when_present(): void
    {
        $app = require $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        self::assertInstanceOf(Application::class, $app);

        $store = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver($app, 'frankenphp');
        $artifact = $store->publish(
            driver: 'frankenphp',
            platform: 'windows-frankenphp-dev',
            capabilities: new RuntimeCapabilities(
                persistent: true,
                concurrent: false,
                streaming: false,
                drainControl: true,
                nativeHttp: true,
                evidenceLevel: 'native-verified',
                nativeIntegrationVerified: true,
                evidenceNotes: ['Validado contra runtime real de FrankenPHP en Windows.'],
            ),
        );
        $store->activateGeneration($artifact->generationId());

        $command = new RuntimeReleasePipelineCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:release-pipeline',
                '--requests=GET:/ok',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('native-verified', $decoded['report']['capability_evidence']['level'] ?? null);
        self::assertSame(true, $decoded['report']['capability_evidence']['native_integration_verified'] ?? null);
        self::assertSame($artifact->generationId(), $decoded['report']['active_capability_evidence']['generation_id'] ?? null);
        self::assertSame('windows-frankenphp-dev', $decoded['report']['active_capability_evidence']['platform'] ?? null);
    }

    public function test_runtime_release_pipeline_command_can_require_published_configuration_when_generation_matches_effective_snapshot(): void
    {
        $this->publishCurrentConfiguration();

        $command = new RuntimeReleasePipelineCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:release-pipeline',
                '--requests=GET:/ok',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
    }

    public function test_runtime_release_pipeline_command_can_require_published_configuration(): void
    {
        $command = new RuntimeReleasePipelineCommand($this->basePath);
        $output = new Output();

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('Published configuration is required for runtime release pipeline');

        $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:release-pipeline',
                '--requests=GET:/ok',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );
    }

    public function test_runtime_release_pipeline_command_fails_when_required_published_configuration_has_drift(): void
    {
        $this->publishCurrentConfiguration();
        $this->createConfigDriftMarker();

        $command = new RuntimeReleasePipelineCommand($this->basePath);
        $output = new Output();

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('effective snapshot differs from the active generation');

        $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:release-pipeline',
                '--requests=GET:/ok',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );
    }

    private function publishBootstrapGeneration(string $profile): string
    {
        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile($profile)
            ->withArtifactDirectory($this->artifactDirectory)
            ->build();

        $store = new BootstrapManifestStore(
            manifest: new BuildManifest($this->artifactDirectory),
            storageRoot: $this->artifactDirectory,
        );
        $artifact = $store->publish($plan);
        $store->activateGeneration($artifact->generationId());

        return $artifact->generationId();
    }

    private function publishCurrentConfiguration(): string
    {
        $app = new Application($this->basePath);
        $repository = $app->make(ConfigRepository::class);
        $repository->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');

        $codec = $app->configSnapshotCodec();
        $baseSnapshot = $repository->snapshot(provenance: $repository->provenance());
        $publishedSnapshot = $repository->snapshot(
            provenance: $baseSnapshot->provenance(),
            configId: $codec->configId($baseSnapshot),
        );

        $artifact = $app->configManifestStore()->publish($publishedSnapshot);
        $app->configManifestStore()->activateGeneration($artifact->generationId());

        return $artifact->generationId();
    }

    private function exportValue(string $value): string
    {
        return var_export($value, true);
    }

    private function createConfigDriftMarker(): void
    {
        $directory = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'config-drift.marker',
            'drift',
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
