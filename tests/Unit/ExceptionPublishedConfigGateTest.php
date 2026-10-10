<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Commands\ExceptionDoctorCommand;
use Quantum\Console\Commands\ExceptionStatusCommand;
use Quantum\Console\Commands\ExceptionValidateCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class ExceptionPublishedConfigGateTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volt-exc-published-gate-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Exception Published Gate',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'exceptions.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'environment' => 'test',
    'runtime' => 'cli',
    'debug' => false,
    'reporting' => [
        'ignore_codes' => [],
        'reporters' => ['exceptions.log'],
    ],
];
PHP
        );

        $this->writeBootstrapApp();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_exception_commands_succeed_by_default_without_published_config(): void
    {
        $doctor = new ExceptionDoctorCommand($this->basePath);
        $status = new ExceptionStatusCommand($this->basePath);
        $validate = new ExceptionValidateCommand($this->basePath);

        self::assertSame(0, $doctor->handle(Input::fromArgv(['volt', 'exceptions:doctor', '--json']), new Output()));
        self::assertSame(0, $status->handle(Input::fromArgv(['volt', 'exceptions:status', '--json']), new Output()));
        self::assertSame(0, $validate->handle(Input::fromArgv(['volt', 'exceptions:validate', '--json']), new Output()));
    }

    public function test_exception_commands_gate_passes_with_active_published_config(): void
    {
        $this->publishCurrentConfiguration();

        $doctor = new ExceptionDoctorCommand($this->basePath);
        $status = new ExceptionStatusCommand($this->basePath);
        $validate = new ExceptionValidateCommand($this->basePath);

        self::assertSame(0, $doctor->handle(Input::fromArgv(['volt', 'exceptions:doctor', '--json', '--require-published-config']), new Output()));
        self::assertSame(0, $status->handle(Input::fromArgv(['volt', 'exceptions:status', '--json', '--require-published-config']), new Output()));
        self::assertSame(0, $validate->handle(Input::fromArgv(['volt', 'exceptions:validate', '--json', '--require-published-config']), new Output()));
    }

    public function test_exception_doctor_gate_fails_when_no_active_generation_exists(): void
    {
        $command = new ExceptionDoctorCommand($this->basePath);

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('no active configuration generation exists');

        $command->handle(Input::fromArgv(['volt', 'exceptions:doctor', '--json', '--require-published-config']), new Output());
    }

    public function test_exception_status_gate_fails_when_published_config_has_drift(): void
    {
        $this->publishCurrentConfiguration();
        $this->writeBootstrapApp(mutateAfterBoot: true);

        $command = new ExceptionStatusCommand($this->basePath);

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('effective snapshot differs from the active generation');

        $command->handle(Input::fromArgv(['volt', 'exceptions:status', '--json', '--require-published-config']), new Output());
    }

    public function test_exception_validate_gate_fails_when_no_active_generation_exists(): void
    {
        $command = new ExceptionValidateCommand($this->basePath);

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('no active configuration generation exists');

        $command->handle(Input::fromArgv(['volt', 'exceptions:validate', '--json', '--require-published-config']), new Output());
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
            ? "\n\$app->make(\\Quantum\\Config\\ConfigRepository::class)->set('exceptions.debug', true);"
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
