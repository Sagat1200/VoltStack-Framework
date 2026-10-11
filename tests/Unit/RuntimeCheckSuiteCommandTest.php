<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\RuntimeCheckSuiteCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use RuntimeException;

/**
 * Tests para el comando CLI {@see RuntimeCheckSuiteCommand}.
 *
 * Estos tests NO requieren un runner real (FrankenPHP/RoadRunner/OpenSwoole).
 * El comando debe fallar honestamente cuando el runner no está disponible:
 * smoke HTTP falla y API PHP nativa no está disponible, pero el report JSON
 * sigue siendo estructuralmente válido y consumible por
 * {@see \VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationReport::fromArray()}.
 */
final class RuntimeCheckSuiteCommandTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-check-suite-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'runtime-evidence.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Runtime Check Suite',
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

    public function test_it_emits_report_json_when_runner_unavailable_honestly(): void
    {
        $command = new RuntimeCheckSuiteCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:check-suite',
                '--driver=frankenphp',
                '--json',
            ]),
            $output,
        );

        // Sin runner real: smoke falla y API nativa no está disponible.
        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('runtime:check-suite', $decoded['command'] ?? null);
        self::assertSame('frankenphp', $decoded['driver'] ?? null);
        self::assertSame(false, $decoded['smoke_passed'] ?? null);
        self::assertSame(false, $decoded['native_api_passed'] ?? null);

        // El report debe tener la estructura correcta.
        $report = $decoded['report'] ?? null;
        self::assertIsArray($report);
        self::assertSame('frankenphp', $report['driver'] ?? null);
        self::assertSame('release', $report['profile'] ?? null);
        self::assertGreaterThan(0, $report['total_checks'] ?? 0);

        // El source debe ser el del driver.
        self::assertSame('frankenphp-real-check-suite', $decoded['source'] ?? null);

        // catalog_audit no debe tener unknowns (IDs del catálogo).
        self::assertSame([], $decoded['catalog_audit']['unknown_check_ids'] ?? null);
    }

    public function test_it_writes_report_to_output_file(): void
    {
        $outputPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime-evidence' . DIRECTORY_SEPARATOR . 'frankenphp-check-suite.json';

        $command = new RuntimeCheckSuiteCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:check-suite',
                '--driver=frankenphp',
                '--output=' . $outputPath,
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertFileExists($outputPath);

        $contents = file_get_contents($outputPath);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);
        self::assertSame('frankenphp', $decoded['driver'] ?? null);
        self::assertSame('frankenphp-real-check-suite', $decoded['metadata']['source'] ?? null);
    }

    public function test_it_rejects_unsupported_driver(): void
    {
        $command = new RuntimeCheckSuiteCommand($this->basePath);
        $output = new Output();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported driver');

        $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:check-suite',
                '--driver=nginx',
            ]),
            $output,
        );
    }

    public function test_it_requires_driver_option(): void
    {
        $command = new RuntimeCheckSuiteCommand($this->basePath);
        $output = new Output();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires --driver');

        $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:check-suite',
            ]),
            $output,
        );
    }

    public function test_it_uses_custom_platform_and_profile(): void
    {
        $command = new RuntimeCheckSuiteCommand($this->basePath);
        $output = new Output();

        $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:check-suite',
                '--driver=roadrunner',
                '--platform=linux-roadrunner-x64',
                '--profile=staging',
                '--json',
            ]),
            $output,
        );

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('roadrunner', $decoded['driver'] ?? null);
        self::assertSame('linux-roadrunner-x64', $decoded['platform'] ?? null);
        self::assertSame('staging', $decoded['report']['profile'] ?? null);
        self::assertSame('roadrunner-real-check-suite', $decoded['source'] ?? null);
    }

    public function test_it_has_expected_command_metadata(): void
    {
        $command = new RuntimeCheckSuiteCommand($this->basePath);

        self::assertSame('runtime:check-suite', $command->name());
        self::assertSame('Runtime', $command->category());
        self::assertNotEmpty($command->description());
        self::assertNotEmpty($command->optionsHelp());
    }

    /**
     * @param mixed $value
     */
    private function exportValue(mixed $value): string
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

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $this->deleteDirectory($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}
