<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Bootstrap\Config\SecretReference;
use Quantum\Config\ConfigRepository;
use Quantum\Console\Commands\ConfigReleaseCheckCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class ConfigReleaseCheckCommandTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-config-release-check-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'config-release-check.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Config Release Check',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            <<<'PHP'
<?php

declare(strict_types=1);

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

        $this->writeBootstrapApp();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_config_release_check_command_renders_json_for_active_published_configuration(): void
    {
        $artifact = $this->publishConfigurationSnapshot();

        $command = new ConfigReleaseCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'config:release-check',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('config:release-check', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame(true, $decoded['report']['require_active_generation'] ?? null);
        self::assertSame(true, $decoded['report']['require_published_match'] ?? null);
        self::assertSame([], $decoded['report']['violations'] ?? null);
        self::assertSame(true, $decoded['report']['status']['has_active_generation'] ?? null);
        self::assertSame(true, $decoded['report']['status']['published_matches_effective'] ?? null);
        self::assertSame($artifact['generation_id'], $decoded['report']['status']['generation_id'] ?? null);
        self::assertSame($artifact['config_id'], $decoded['report']['status']['effective_config_id'] ?? null);
    }

    public function test_config_release_check_command_fails_by_default_when_no_active_generation_exists(): void
    {
        $command = new ConfigReleaseCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'config:release-check',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['passed'] ?? null);
        self::assertContains(
            'No hay una generacion de configuracion activa.',
            $decoded['report']['violations'] ?? [],
        );
    }

    public function test_config_release_check_command_can_allow_missing_generation(): void
    {
        $command = new ConfigReleaseCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'config:release-check',
                '--allow-missing-generation',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame(false, $decoded['report']['require_active_generation'] ?? null);
        self::assertSame(false, $decoded['report']['status']['has_active_generation'] ?? null);
    }

    public function test_config_release_check_command_fails_on_drift_and_can_relax_it(): void
    {
        $this->publishConfigurationSnapshot();
        $this->writeBootstrapApp(mutateAfterBoot: true);

        $command = new ConfigReleaseCheckCommand($this->basePath);

        $failingOutput = new Output();
        $failingExitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'config:release-check',
                '--json',
            ]),
            $failingOutput,
        );

        self::assertSame(1, $failingExitCode);

        $failingDecoded = json_decode(trim($failingOutput->stdout()), true);
        self::assertIsArray($failingDecoded);
        self::assertSame(false, $failingDecoded['report']['passed'] ?? null);
        self::assertContains(
            'El snapshot efectivo difiere de la generacion de configuracion activa.',
            $failingDecoded['report']['violations'] ?? [],
        );

        $relaxedOutput = new Output();
        $relaxedExitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'config:release-check',
                '--allow-drift',
                '--json',
            ]),
            $relaxedOutput,
        );

        self::assertSame(0, $relaxedExitCode);

        $relaxedDecoded = json_decode(trim($relaxedOutput->stdout()), true);
        self::assertIsArray($relaxedDecoded);
        self::assertSame(true, $relaxedDecoded['report']['passed'] ?? null);
        self::assertSame(false, $relaxedDecoded['report']['require_published_match'] ?? null);
        self::assertSame(false, $relaxedDecoded['report']['status']['published_matches_effective'] ?? null);
    }

    public function test_config_release_check_command_emits_telemetry(): void
    {
        $this->publishConfigurationSnapshot();

        $command = new ConfigReleaseCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'config:release-check',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('config_release_check', $telemetry);
        self::assertStringContainsString('"source":"config"', $telemetry);
    }

    /**
     * @return array{generation_id: string, config_id: string}
     */
    private function publishConfigurationSnapshot(): array
    {
        $app = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($app);
        $bootstrapper->loadConfiguration();

        $repository = $app->make(ConfigRepository::class);
        $repository->set('app.key', new SecretReference('APP_KEY'));

        $codec = $app->configSnapshotCodec();
        $baseSnapshot = $repository->snapshot(provenance: $repository->provenance());
        $publishedSnapshot = $repository->snapshot(
            provenance: $baseSnapshot->provenance(),
            configId: $codec->configId($baseSnapshot),
        );

        $artifact = $app->configManifestStore()->publish($publishedSnapshot);
        $app->configManifestStore()->activateGeneration($artifact->generationId());

        return [
            'generation_id' => $artifact->generationId(),
            'config_id' => $artifact->configId(),
        ];
    }

    private function writeBootstrapApp(bool $mutateAfterBoot = false): void
    {
        $escapedBasePath = $this->exportValue($this->basePath);
        $mutation = $mutateAfterBoot
            ? "\n\$app->make(\\Quantum\\Config\\ConfigRepository::class)->set('app.name', 'Mutated Config');"
            : '';

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
\$app->boot();{$mutation}

return \$app;
PHP
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
