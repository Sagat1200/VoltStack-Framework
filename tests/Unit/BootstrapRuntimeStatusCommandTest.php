<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\Manifest\BootstrapManifestStore;
use Quantum\Console\Commands\BootstrapStatusCommand;
use Quantum\Console\Commands\RuntimeStatusCommand;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Compilation\BuildManifest;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Budget\RuntimeBudgetBaseline;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationReport;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationStoreResolver;

final class BootstrapRuntimeStatusCommandTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    private string $artifactDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-bootstrap-runtime-status-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'status.jsonl';
        $this->artifactDirectory = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'bootstrap';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Bootstrap Runtime Status',
    'env' => 'testing',
    'providers' => [],
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

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => 'frankenphp',
    'budgets' => [
        'drivers' => [
            'frankenphp' => [
                'total_ms' => 80,
                'request_ms' => 30,
            ],
        ],
    ],
];
PHP
        );

        $escapedBasePath = $this->exportValue($this->basePath);
        $driftMarkerPath = $this->exportValue($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'config-drift.marker');

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
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

    public function test_bootstrap_status_command_reports_active_generation_and_emits_telemetry(): void
    {
        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->withArtifactDirectory($this->artifactDirectory)
            ->build();

        $store = new BootstrapManifestStore(
            manifest: new BuildManifest($this->artifactDirectory),
            storageRoot: $this->artifactDirectory,
        );
        $artifact = $store->publish($plan);
        $store->activateGeneration($artifact->generationId());

        $command = new BootstrapStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:status',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Bootstrap status:', $output->stdout());
        self::assertStringContainsString('Environment: testing', $output->stdout());
        self::assertStringContainsString('Booted: yes', $output->stdout());
        self::assertStringContainsString('Active generation: yes', $output->stdout());
        self::assertStringContainsString($artifact->generationId(), $output->stdout());
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"bootstrap_status"', $telemetry);
        self::assertStringContainsString('"signal_type":"event"', $telemetry);
    }

    public function test_runtime_status_command_reports_driver_capabilities_and_emits_telemetry(): void
    {
        $command = new RuntimeStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:status',
                '--driver=frankenphp',
                '--max-requests=4',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Runtime status:', $output->stdout());
        self::assertStringContainsString('Driver: frankenphp', $output->stdout());
        self::assertStringContainsString('Max requests: 4', $output->stdout());
        self::assertStringContainsString('Persistent: yes', $output->stdout());
        self::assertStringContainsString('Concurrent: no', $output->stdout());
        self::assertStringContainsString('Recommended total budget: 80.000 ms', $output->stdout());
        self::assertStringContainsString('Recommended request budget: 30.000 ms', $output->stdout());
        self::assertStringContainsString('Budget source: config', $output->stdout());
        self::assertStringContainsString('Rollout ready: yes', $output->stdout());
        self::assertStringContainsString('Rollout strategy: progressive-drain', $output->stdout());
        self::assertStringContainsString('Supported drivers: frankenphp, sapi', $output->stdout());
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"runtime_status"', $telemetry);
        self::assertStringContainsString('"budget_source":"config"', $telemetry);
        self::assertStringContainsString('"rollout_ready":true', $telemetry);
        self::assertStringContainsString('"rollout_strategy":"progressive-drain"', $telemetry);
    }

    public function test_runtime_status_command_can_require_published_configuration_when_generation_matches_effective_snapshot(): void
    {
        $this->publishCurrentConfiguration();

        $command = new RuntimeStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:status',
                '--driver=frankenphp',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('runtime:status', $decoded['command'] ?? null);
        self::assertSame('frankenphp', $decoded['report']['driver'] ?? null);
    }

    public function test_bootstrap_status_command_can_render_stable_json_output(): void
    {
        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->withArtifactDirectory($this->artifactDirectory)
            ->build();

        $store = new BootstrapManifestStore(
            manifest: new BuildManifest($this->artifactDirectory),
            storageRoot: $this->artifactDirectory,
        );
        $artifact = $store->publish($plan);
        $store->activateGeneration($artifact->generationId());

        $command = new BootstrapStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:status',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('bootstrap:status', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['healthy'] ?? null);
        self::assertSame($artifact->generationId(), $decoded['report']['generation_id'] ?? null);
        self::assertSame(1, $decoded['report']['schema_version'] ?? null);
    }

    public function test_bootstrap_status_command_resolves_relative_artifact_directory_against_base_path(): void
    {
        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->withArtifactDirectory($this->artifactDirectory)
            ->build();

        $store = new BootstrapManifestStore(
            manifest: new BuildManifest($this->artifactDirectory),
            storageRoot: $this->artifactDirectory,
        );
        $artifact = $store->publish($plan);
        $store->activateGeneration($artifact->generationId());

        $command = new BootstrapStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:status',
                '--artifact-dir=storage/framework/bootstrap',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame($this->artifactDirectory, $decoded['report']['artifact_directory'] ?? null);
        self::assertSame($artifact->generationId(), $decoded['report']['generation_id'] ?? null);
    }

    public function test_runtime_status_command_can_render_stable_json_output(): void
    {
        $command = new RuntimeStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:status',
                '--driver=frankenphp',
                '--max-requests=3',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('runtime:status', $decoded['command'] ?? null);
        self::assertSame('frankenphp', $decoded['report']['driver'] ?? null);
        self::assertSame(3, $decoded['report']['max_requests'] ?? null);
        self::assertSame(true, $decoded['report']['persistent'] ?? null);
        self::assertSame(80, $decoded['report']['recommended_budget']['total_budget_ms'] ?? null);
        self::assertSame(30, $decoded['report']['recommended_budget']['request_budget_ms'] ?? null);
        self::assertSame('config', $decoded['report']['recommended_budget']['source'] ?? null);
        self::assertSame(true, $decoded['report']['rollout_readiness']['ready'] ?? null);
        self::assertSame('progressive-drain', $decoded['report']['rollout_readiness']['strategy'] ?? null);
    }

    public function test_runtime_status_command_uses_the_active_published_calibration_when_no_config_budget_exists(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => 'frankenphp',
];
PHP
        );

        $app = require $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        self::assertInstanceOf(Application::class, $app);

        $store = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver($app, 'frankenphp');
        $artifact = $store->publish(new RuntimeBudgetCalibrationReport(
            driver: 'frankenphp',
            profile: 'release',
            requestDefinitions: ['GET:/health'],
            warmupIterations: 1,
            measuredIterations: 3,
            safetyMultiplier: 1.25,
            currentBaseline: new RuntimeBudgetBaseline('frankenphp', 50.0, 25.0, 'adapter-default'),
            recommendedBudget: new RuntimeBudgetBaseline('frankenphp', 61.0, 29.0, 'empirical-calibration'),
            samples: [
                ['iteration' => 1, 'total_duration_ms' => 30.0, 'max_request_duration_ms' => 14.0, 'request_count' => 1],
                ['iteration' => 2, 'total_duration_ms' => 41.0, 'max_request_duration_ms' => 20.0, 'request_count' => 1],
                ['iteration' => 3, 'total_duration_ms' => 48.8, 'max_request_duration_ms' => 23.2, 'request_count' => 1],
            ],
            failures: [],
            totalStats: ['min' => 30.0, 'avg' => 39.933, 'p95' => 48.8, 'max' => 48.8],
            requestStats: ['min' => 14.0, 'avg' => 19.066, 'p95' => 23.2, 'max' => 23.2],
        ));
        $store->activateGeneration($artifact->generationId());

        $command = new RuntimeStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:status',
                '--driver=frankenphp',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('published-calibration', $decoded['report']['recommended_budget']['source'] ?? null);
        self::assertEquals(61.0, $decoded['report']['recommended_budget']['total_budget_ms'] ?? null);
        self::assertEquals(29.0, $decoded['report']['recommended_budget']['request_budget_ms'] ?? null);
        self::assertSame($artifact->generationId(), $decoded['report']['active_calibration']['generation_id'] ?? null);
        self::assertSame(true, $decoded['report']['rollout_readiness']['ready'] ?? null);
        self::assertSame('progressive-drain', $decoded['report']['rollout_readiness']['strategy'] ?? null);
    }

    public function test_runtime_status_command_flags_persistent_runtime_without_empirical_or_config_budget_as_not_ready_for_rollout(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => 'frankenphp',
];
PHP
        );

        $command = new RuntimeStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:status',
                '--driver=frankenphp',
                '--strict-rollout',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['rollout_readiness']['ready'] ?? null);
        self::assertSame('progressive-drain', $decoded['report']['rollout_readiness']['strategy'] ?? null);
        self::assertSame('adapter-default', $decoded['report']['rollout_readiness']['budget_source'] ?? null);
        self::assertContains(
            'No existe budget runtime configurado o calibrado para un rollout persistente controlado.',
            $decoded['report']['rollout_readiness']['gaps'] ?? [],
        );
    }

    public function test_runtime_status_command_can_require_published_configuration(): void
    {
        $command = new RuntimeStatusCommand($this->basePath);
        $output = new Output();

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('Published configuration is required for runtime status inspection');

        $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:status',
                '--driver=frankenphp',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );
    }

    public function test_runtime_status_command_fails_when_required_published_configuration_has_drift(): void
    {
        $this->publishCurrentConfiguration();
        $this->createConfigDriftMarker();

        $command = new RuntimeStatusCommand($this->basePath);
        $output = new Output();

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('effective snapshot differs from the active generation');

        $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:status',
                '--driver=frankenphp',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );
    }

    public function test_bootstrap_status_command_strict_mode_fails_when_no_active_generation_exists(): void
    {
        $command = new BootstrapStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:status',
                '--strict',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No hay una generacion bootstrap activa.', $output->stdout());
    }

    public function test_bootstrap_status_command_can_require_published_configuration_when_generation_matches_effective_snapshot(): void
    {
        $this->publishCurrentConfiguration();

        $command = new BootstrapStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:status',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('bootstrap:status', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['booted'] ?? null);
    }

    public function test_bootstrap_status_command_can_require_published_configuration(): void
    {
        $command = new BootstrapStatusCommand($this->basePath);
        $output = new Output();

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('Published configuration is required for bootstrap status inspection');

        $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:status',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );
    }

    public function test_bootstrap_status_command_fails_when_required_published_configuration_has_drift(): void
    {
        $this->publishCurrentConfiguration();
        $this->createConfigDriftMarker();

        $command = new BootstrapStatusCommand($this->basePath);
        $output = new Output();

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('effective snapshot differs from the active generation');

        $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:status',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );
    }

    private function exportValue(string $value): string
    {
        return var_export($value, true);
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
