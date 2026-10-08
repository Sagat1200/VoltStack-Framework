<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\BootstrapBenchmarkCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class BootstrapBenchmarkCommandTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-bootstrap-benchmark-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'benchmark.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Bootstrap Benchmark',
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

    public function test_bootstrap_benchmark_command_renders_json_with_cold_and_warm_runs(): void
    {
        $command = new BootstrapBenchmarkCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:benchmark',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('bootstrap:benchmark', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame('release', $decoded['report']['profile'] ?? null);
        self::assertArrayHasKey('cold_total_duration_ms', $decoded['report'] ?? []);
        self::assertArrayHasKey('warm_total_duration_ms', $decoded['report'] ?? []);
        self::assertArrayHasKey('delta_ms', $decoded['report'] ?? []);
        self::assertArrayHasKey('warm_faster', $decoded['report'] ?? []);
        self::assertStringContainsString(
            'storage\\framework\\bootstrap\\benchmark',
            (string) ($decoded['report']['artifact_directory'] ?? ''),
        );
        self::assertSame('READY', $decoded['report']['cold']['state'] ?? null);
        self::assertSame('READY', $decoded['report']['warm']['state'] ?? null);
    }

    public function test_bootstrap_benchmark_command_fails_when_budget_is_exceeded(): void
    {
        $command = new BootstrapBenchmarkCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:benchmark',
                '--budget-total-ms=0.001',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['passed'] ?? null);
        self::assertNotEmpty($decoded['report']['violations'] ?? []);
    }

    public function test_bootstrap_benchmark_command_emits_telemetry(): void
    {
        $command = new BootstrapBenchmarkCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:benchmark',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"bootstrap_benchmark"', $telemetry);
        self::assertStringContainsString('"type":"bootstrap_phase"', $telemetry);
    }

    public function test_bootstrap_benchmark_command_can_require_published_configuration(): void
    {
        $command = new BootstrapBenchmarkCommand($this->basePath);
        $output = new Output();

        $this->expectException(\Quantum\Config\Publication\PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('Published configuration is required for bootstrap release checks');

        $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:benchmark',
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
