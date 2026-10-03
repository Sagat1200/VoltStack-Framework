<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\Manifest\BootstrapManifestStore;
use Quantum\Console\Commands\BootstrapStatusCommand;
use Quantum\Console\Commands\RuntimeStatusCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Compilation\BuildManifest;

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
];
PHP
        );

        $escapedBasePath = $this->exportValue($this->basePath);

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
        self::assertStringContainsString('Supported drivers: frankenphp', $output->stdout());
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"runtime_status"', $telemetry);
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
