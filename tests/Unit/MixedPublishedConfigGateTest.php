<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Commands\DatabaseStatusCommand;
use Quantum\Console\Commands\RouteClearCommand;
use Quantum\Console\Commands\ControllerCompileClearCommand;
use Quantum\Console\Commands\AuthSessionsCleanupCommand;
use Quantum\Console\Commands\ConfigStatusCommand;
use Quantum\Console\Commands\CacheClearCommand;
use Quantum\Console\Commands\ContainerStatusCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class MixedPublishedConfigGateTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volt-mixed-published-gate-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Mixed Published Gate',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        $this->writeMinimalConfigs();
        $this->writeBootstrapApp();
    }

    private function writeMinimalConfigs(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'default' => 'array',
    'connections' => [
        'array' => [
            'driver' => 'array',
        ],
    ],
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_cfg044_commands_succeed_by_default_without_published_config(): void
    {
        $routes = new RouteClearCommand($this->basePath);
        $ctrl = new ControllerCompileClearCommand($this->basePath);
        $auth = new AuthSessionsCleanupCommand($this->basePath);
        $config = new ConfigStatusCommand($this->basePath);
        $cache = new CacheClearCommand($this->basePath);
        $container = new ContainerStatusCommand($this->basePath);

        // RouteClear no soporta --json; el closure usa stdout humano.
        // Usar una salida custom no afecta el gate (gate corre antes del closure).
        self::assertSame(0, $routes->handle(Input::fromArgv(['volt', 'route:clear']), new Output()));
        self::assertSame(0, $ctrl->handle(Input::fromArgv(['volt', 'controller-compiler:clear']), new Output()));
        self::assertSame(0, $auth->handle(Input::fromArgv(['volt', 'auth:sessions:cleanup']), new Output()));
        self::assertSame(0, $config->handle(Input::fromArgv(['volt', 'config:status', '--json']), new Output()));
        self::assertSame(0, $cache->handle(Input::fromArgv(['volt', 'cache:clear', '--compiled-only']), new Output()));
        self::assertSame(0, $container->handle(Input::fromArgv(['volt', 'container:status', '--json', '--format=brief']), new Output()));
    }

    public function test_cfg044_commands_gate_passes_with_active_published_config(): void
    {
        $this->publishCurrentConfiguration();

        $routes = new RouteClearCommand($this->basePath);
        $ctrl = new ControllerCompileClearCommand($this->basePath);
        $auth = new AuthSessionsCleanupCommand($this->basePath);
        $config = new ConfigStatusCommand($this->basePath);
        $cache = new CacheClearCommand($this->basePath);
        $container = new ContainerStatusCommand($this->basePath);

        self::assertSame(0, $routes->handle(Input::fromArgv(['volt', 'route:clear', '--require-published-config']), new Output()));
        self::assertSame(0, $ctrl->handle(Input::fromArgv(['volt', 'controller-compiler:clear', '--require-published-config']), new Output()));
        self::assertSame(0, $auth->handle(Input::fromArgv(['volt', 'auth:sessions:cleanup', '--require-published-config']), new Output()));
        self::assertSame(0, $config->handle(Input::fromArgv(['volt', 'config:status', '--json', '--require-published-config']), new Output()));
        self::assertSame(0, $cache->handle(Input::fromArgv(['volt', 'cache:clear', '--compiled-only', '--require-published-config']), new Output()));
        self::assertSame(0, $container->handle(Input::fromArgv(['volt', 'container:status', '--json', '--format=brief', '--require-published-config']), new Output()));
    }

    public function test_cfg044_database_status_gate_fails_when_no_active_generation_exists(): void
    {
        $command = new DatabaseStatusCommand($this->basePath);

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('no active configuration generation exists');

        $command->handle(Input::fromArgv(['volt', 'database:status', '--require-published-config']), new Output());
    }

    public function test_cfg044_config_status_gate_fails_when_published_config_has_drift(): void
    {
        $this->publishCurrentConfiguration();
        $this->writeBootstrapApp(mutateAfterBoot: true);

        $command = new ConfigStatusCommand($this->basePath);

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('effective snapshot differs from the active generation');

        $command->handle(Input::fromArgv(['volt', 'config:status', '--json', '--require-published-config']), new Output());
    }

    public function test_cfg044_cache_clear_gate_fails_when_no_active_generation_exists(): void
    {
        $command = new CacheClearCommand($this->basePath);

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('no active configuration generation exists');

        $command->handle(Input::fromArgv(['volt', 'cache:clear', '--compiled-only', '--require-published-config']), new Output());
    }

    private function publishCurrentConfiguration(): string
    {
        $app = new \VoltStack\Framework\Application($this->basePath);
        $bootstrapper = new \Quantum\Bootstrap\Bootstrapper($app);
        $bootstrapper->loadConfiguration();

        $repository = $app->make(\Quantum\Config\ConfigRepository::class);
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

    private function writeBootstrapApp(bool $mutateAfterBoot = false): void
    {
        $escapedBasePath = var_export($this->basePath, true);
        $mutation = $mutateAfterBoot
            ? "\n\$app->make(\\Quantum\\Config\\ConfigRepository::class)->set('app.env', 'mutated-after-boot');"
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

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
