<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\RuntimeSmokeCheckCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class RuntimeSmokeCheckCommandTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-smoke-check-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'runtime-smoke.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Runtime Smoke Check',
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

    public function test_runtime_smoke_check_command_renders_json_for_successful_requests(): void
    {
        $command = new RuntimeSmokeCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:smoke-check',
                '--requests=GET:/ok,GET:/health',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('runtime:smoke-check', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame('frankenphp', $decoded['report']['driver'] ?? null);
        self::assertSame(2, $decoded['report']['request_count'] ?? null);
        self::assertSame(200, $decoded['report']['requests'][0]['status_code'] ?? null);
        self::assertSame(50, $decoded['report']['budget']['total_budget_ms'] ?? null);
        self::assertSame(25, $decoded['report']['budget']['request_budget_ms'] ?? null);
        self::assertSame('adapter-default', $decoded['report']['budget_baseline']['source'] ?? null);
        self::assertSame(true, $decoded['report']['reuse_guard']['passed'] ?? null);
        self::assertSame(false, $decoded['report']['reuse_guard']['active_generation_observed'] ?? null);
        self::assertSame([], $decoded['report']['violations'] ?? null);
    }

    public function test_runtime_smoke_check_command_fails_when_a_request_returns_client_or_server_error(): void
    {
        $command = new RuntimeSmokeCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:smoke-check',
                '--requests=GET:/missing',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['passed'] ?? null);
        self::assertNotEmpty($decoded['report']['violations'] ?? []);
        self::assertSame(404, $decoded['report']['requests'][0]['status_code'] ?? null);
    }

    public function test_runtime_smoke_check_command_fails_when_budget_is_exceeded(): void
    {
        $command = new RuntimeSmokeCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:smoke-check',
                '--requests=GET:/ok',
                '--budget-request-ms=0.001',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['passed'] ?? null);
        self::assertNotEmpty($decoded['report']['budget']['violations'] ?? []);
    }

    public function test_runtime_smoke_check_command_emits_telemetry(): void
    {
        $command = new RuntimeSmokeCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:smoke-check',
                '--requests=GET:/ok',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"runtime_smoke"', $telemetry);
        self::assertStringContainsString('"budget_source":"adapter-default"', $telemetry);
        self::assertStringContainsString('"reuse_guard_passed":true', $telemetry);
    }

    public function test_runtime_smoke_check_command_can_require_published_configuration(): void
    {
        $command = new RuntimeSmokeCheckCommand($this->basePath);
        $output = new Output();

        $this->expectException(\Quantum\Config\Publication\PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('Published configuration is required for runtime checks');

        $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:smoke-check',
                '--requests=GET:/ok',
                '--require-published-config',
                '--json',
            ]),
            $output,
        );
    }

    public function test_runtime_smoke_check_command_fails_when_a_request_materializes_a_new_bootstrap_generation(): void
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-smoke-check-rebuild-' . uniqid('', true);
        $telemetryPath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'runtime-smoke.jsonl';

        mkdir($basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Runtime Smoke Rebuild Guard',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => 'frankenphp',
];
PHP
        );

        file_put_contents(
            $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'telemetry.php',
            <<<PHP
<?php

declare(strict_types=1);

return [
    'exporter' => 'jsonl',
    'jsonl_path' => {$this->exportValue($telemetryPath)},
];
PHP
        );

        $escapedBasePath = $this->exportValue($basePath);

        file_put_contents(
            $basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Bootstrap\Manifest\BootstrapManifestStore;
use Quantum\Compilation\BuildManifest;
use Quantum\Http\Request;
use Quantum\Http\Response;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;

\$app = new Application({$escapedBasePath});
\$app->instance(KernelContract::class, new class({$escapedBasePath}) implements KernelContract {
    public function __construct(private string \$basePath)
    {
    }

    public function handle(Request \$request): Response
    {
        \$artifactDir = \$this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'bootstrap';
        \$store = new BootstrapManifestStore(new BuildManifest(\$artifactDir), \$artifactDir);
        \$plan = ApplicationBuilder::create(\$this->basePath)->withEnvironment('testing')->withProfile('mutated')->build();
        \$artifact = \$store->publish(\$plan);
        \$store->activateGeneration(\$artifact->generationId());

        return new Response('mutated');
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

        try {
            $command = new RuntimeSmokeCheckCommand($basePath);
            $output = new Output();

            $exitCode = $command->handle(
                Input::fromArgv([
                    'volt',
                    'runtime:smoke-check',
                    '--requests=GET:/mutates',
                    '--json',
                ]),
                $output,
            );

            self::assertSame(1, $exitCode);

            $decoded = json_decode(trim($output->stdout()), true);
            self::assertIsArray($decoded);
            self::assertSame(false, $decoded['report']['passed'] ?? null);
            self::assertSame(false, $decoded['report']['reuse_guard']['passed'] ?? null);
            self::assertNotEmpty($decoded['report']['reuse_guard']['violations'] ?? []);
        } finally {
            $this->deleteDirectory($basePath);
        }
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
