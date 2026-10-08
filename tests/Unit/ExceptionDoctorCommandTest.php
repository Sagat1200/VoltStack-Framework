<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\ExceptionDoctorCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Exceptions\Compilation\ExceptionPlanStore;
use VoltStack\Framework\Application;

final class ExceptionDoctorCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-exception-doctor-command-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Exception Doctor',
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
    'environment' => 'development',
    'debug' => false,
    'runtime' => 'sapi',
    'reporting' => [
        'buffer_records' => 256,
        'buffer_bytes' => 2097152,
    ],
    'rendering' => [
        'api_format' => 'problem_json',
        'browser_format' => 'html',
        'spa_versions' => [1],
        'cache_control' => 'no-store',
    ],
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

    public function test_exception_doctor_warns_when_published_plan_is_missing(): void
    {
        $command = new ExceptionDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:doctor',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('warn', $decoded['report']['outcome'] ?? null);
        self::assertContains('published_plan_missing', $decoded['report']['warnings'] ?? []);
        self::assertSame([], $decoded['report']['issues'] ?? null);
    }

    public function test_exception_doctor_fails_when_configuration_is_invalid(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'exceptions.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'environment' => 'production',
    'debug' => true,
    'runtime' => 'sapi',
];
PHP
        );

        $command = new ExceptionDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:doctor',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('fail', $decoded['report']['outcome'] ?? null);
        self::assertContains('config_invalid', $decoded['report']['issues'] ?? []);
        self::assertContains('debug_enabled_in_production', $decoded['report']['issues'] ?? []);
    }

    public function test_exception_doctor_detects_obsolete_published_plan_and_strict_mode_fails(): void
    {
        $this->publishCurrentPlan();

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'exceptions.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'environment' => 'development',
    'debug' => true,
    'runtime' => 'sapi',
    'reporting' => [
        'buffer_records' => 256,
        'buffer_bytes' => 2097152,
    ],
    'rendering' => [
        'api_format' => 'problem_json',
        'browser_format' => 'html',
        'spa_versions' => [1],
        'cache_control' => 'no-store',
    ],
];
PHP
        );

        $command = new ExceptionDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:doctor',
                '--strict',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('warn', $decoded['report']['outcome'] ?? null);
        self::assertContains('published_plan_obsolete', $decoded['report']['warnings'] ?? []);
    }

    private function publishCurrentPlan(): void
    {
        $app = $this->application();
        $plan = $app->make(\Quantum\Exceptions\Compilation\ExceptionPlanCompiler::class)
            ->compile((array) $app->config('exceptions', []));

        $app->make(ExceptionPlanStore::class)->persist($plan);
    }

    private function application(): Application
    {
        $app = new Application($this->basePath);
        $bootstrapper = new \Quantum\Bootstrap\Bootstrapper($app);
        $bootstrapper->loadConfiguration();
        $app->boot();

        return $app;
    }

    private function writeBootstrapApp(): void
    {
        $escapedBasePath = var_export($this->basePath, true);

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
