<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\RuntimeBudgetCalibrateCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class RuntimeBudgetCalibrateCommandTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-budget-calibrate-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'runtime-calibration.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Runtime Budget Calibration',
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

        $escapedBasePath = $this->exportValue($this->basePath);

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
        if (\$request->path() === '/ok') {
            usleep(1000);
            return new Response('ok');
        }

        if (\$request->path() === '/health') {
            usleep(2000);
            return new Response('healthy');
        }

        return new Response('missing', 404);
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

    public function test_runtime_budget_calibrate_command_renders_json_with_empirical_recommendation(): void
    {
        $command = new RuntimeBudgetCalibrateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:budget-calibrate',
                '--warmup=1',
                '--iterations=3',
                '--multiplier=1.50',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('runtime:budget-calibrate', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame(1, $decoded['report']['warmup_iterations'] ?? null);
        self::assertSame(3, $decoded['report']['measured_iterations'] ?? null);
        self::assertSame(3, $decoded['report']['successful_iterations'] ?? null);
        self::assertSame('adapter-default', $decoded['report']['current_baseline']['source'] ?? null);
        self::assertSame('empirical-calibration', $decoded['report']['recommended_budget']['source'] ?? null);
        self::assertGreaterThan(0, $decoded['report']['recommended_budget']['total_budget_ms'] ?? 0);
        self::assertGreaterThan(0, $decoded['report']['recommended_budget']['request_budget_ms'] ?? 0);
        self::assertNotEmpty($decoded['report']['statistics']['total_duration_ms'] ?? []);
    }

    public function test_runtime_budget_calibrate_command_fails_when_measurements_fail(): void
    {
        $command = new RuntimeBudgetCalibrateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:budget-calibrate',
                '--requests=GET:/missing',
                '--iterations=2',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['passed'] ?? null);
        self::assertSame(0, $decoded['report']['successful_iterations'] ?? null);
        self::assertSame(2, $decoded['report']['failed_iterations'] ?? null);
        self::assertNotEmpty($decoded['report']['failures'] ?? []);
    }

    public function test_runtime_budget_calibrate_command_emits_telemetry(): void
    {
        $command = new RuntimeBudgetCalibrateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:budget-calibrate',
                '--iterations=2',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"runtime_budget_calibration"', $telemetry);
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
