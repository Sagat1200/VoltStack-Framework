<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\Manifest\BootstrapManifestStore;
use Quantum\Console\Commands\RuntimeReleasePipelineCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Compilation\BuildManifest;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationStoreResolver;

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
        self::assertStringContainsString('"drain_required":true', $telemetry);
        self::assertStringContainsString('"drain_action":"drain"', $telemetry);
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

    private function exportValue(string $value): string
    {
        return var_export($value, true);
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
