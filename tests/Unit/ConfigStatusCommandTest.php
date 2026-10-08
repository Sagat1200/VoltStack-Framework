<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Bootstrap\Config\SecretReference;
use Quantum\Config\ConfigRepository;
use Quantum\Console\Commands\ConfigStatusCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class ConfigStatusCommandTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-config-status-command-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'config-status.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Config Status',
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

    public function test_config_status_command_reports_active_generation_and_emits_telemetry(): void
    {
        $this->publishConfigurationSnapshot();

        $command = new ConfigStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'config:status',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Config status:', $output->stdout());
        self::assertStringContainsString('Environment: testing', $output->stdout());
        self::assertStringContainsString('Scope: command', $output->stdout());
        self::assertStringContainsString('Scope depth: 1', $output->stdout());
        self::assertStringContainsString('Active generation: yes', $output->stdout());
        self::assertStringContainsString('Published matches effective: yes', $output->stdout());
        self::assertStringContainsString('Scope lineage:', $output->stdout());
        self::assertStringContainsString('- [0] root', $output->stdout());
        self::assertStringContainsString('- [1] command', $output->stdout());
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"config_status"', $telemetry);
        self::assertStringContainsString('"source":"config"', $telemetry);
    }

    public function test_config_status_command_can_render_stable_json_output(): void
    {
        $artifact = $this->publishConfigurationSnapshot();

        $command = new ConfigStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'config:status',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('config:status', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['healthy'] ?? null);
        self::assertSame('command', $decoded['report']['scope_name'] ?? null);
        self::assertSame(1, $decoded['report']['scope_depth'] ?? null);
        self::assertNull($decoded['report']['tenant_context'] ?? null);
        self::assertSame(true, $decoded['report']['has_active_generation'] ?? null);
        self::assertSame($artifact['generation_id'], $decoded['report']['generation_id'] ?? null);
        self::assertSame($artifact['config_id'], $decoded['report']['effective_config_id'] ?? null);
        self::assertSame($artifact['config_id'], $decoded['report']['published_config_id'] ?? null);
        self::assertCount(2, $decoded['report']['scope_lineage'] ?? []);
        self::assertSame('root', $decoded['report']['scope_lineage'][0]['kind'] ?? null);
        self::assertSame('command', $decoded['report']['scope_lineage'][1]['kind'] ?? null);
        self::assertSame('[redacted]', $decoded['report']['redacted_config']['database']['connections']['default']['password'] ?? null);
    }

    public function test_config_status_command_strict_mode_fails_when_no_active_generation_exists(): void
    {
        $command = new ConfigStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'config:status',
                '--strict',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No hay una generacion de configuracion activa.', $output->stdout());
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
