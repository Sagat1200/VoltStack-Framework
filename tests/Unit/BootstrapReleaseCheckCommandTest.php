<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\BootstrapReleaseCheckCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class BootstrapReleaseCheckCommandTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-bootstrap-release-check-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'release-check.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Bootstrap Release Check',
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

    public function test_release_check_command_renders_json_and_resolves_relative_artifact_directory(): void
    {
        $command = new BootstrapReleaseCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:release-check',
                '--artifact-dir=storage/framework/bootstrap',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('bootstrap:release-check', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame('release', $decoded['report']['profile'] ?? null);
        self::assertSame('READY', $decoded['report']['state'] ?? null);
        self::assertSame([], $decoded['report']['budget']['violations'] ?? null);
        self::assertIsString($decoded['report']['manifest_path'] ?? null);
        self::assertStringStartsWith(
            $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR,
            $decoded['report']['manifest_path'],
        );
        self::assertStringEndsWith('bootstrap.manifest.php', $decoded['report']['manifest_path']);
        self::assertCount(7, $decoded['report']['phase_profiles'] ?? []);
    }

    public function test_release_check_command_returns_failure_when_total_budget_is_exceeded(): void
    {
        $command = new BootstrapReleaseCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:release-check',
                '--budget-total-ms=0.001',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['passed'] ?? null);
        self::assertNotEmpty($decoded['report']['budget']['violations'] ?? []);
        self::assertStringContainsString(
            'budget total',
            implode(' ', $decoded['report']['budget']['violations'] ?? []),
        );
    }

    public function test_release_check_command_emits_budget_and_phase_telemetry(): void
    {
        $command = new BootstrapReleaseCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'bootstrap:release-check',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"bootstrap_phase"', $telemetry);
        self::assertStringContainsString('"type":"bootstrap_profile"', $telemetry);
        self::assertStringContainsString('"type":"bootstrap_budget"', $telemetry);
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
