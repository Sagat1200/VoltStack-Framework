<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\ExceptionCompileCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;
use Quantum\Exceptions\Compilation\ExceptionPlanStore;
use VoltStack\Framework\Application;

final class ExceptionCompileCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-exception-compile-command-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Exception Compile',
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
    'rendering' => [
        'api_format' => 'problem_json',
        'browser_format' => 'html',
        'spa_versions' => [1],
        'cache_control' => 'no-store',
    ],
];
PHP
        );

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

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_exception_compile_command_publishes_and_verifies_the_plan(): void
    {
        $command = new ExceptionCompileCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:compile',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('exceptions:compile', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame(false, $decoded['report']['check_only'] ?? null);
        self::assertSame(true, $decoded['report']['published'] ?? null);
        self::assertSame(true, $decoded['report']['verified'] ?? null);
        self::assertSame('development', $decoded['report']['environment'] ?? null);
        self::assertSame('sapi', $decoded['report']['runtime'] ?? null);
        self::assertSame([1], $decoded['report']['spa_versions'] ?? null);
        self::assertIsString($decoded['report']['artifact_path'] ?? null);
        self::assertFileExists($decoded['report']['artifact_path']);

        $app = $this->application();
        $loaded = $app->make(ExceptionPlanStore::class)->load();

        self::assertInstanceOf(ExceptionCompilationPlan::class, $loaded);
        self::assertSame($decoded['report']['fingerprint'] ?? null, $loaded?->fingerprint());
    }

    public function test_exception_compile_command_can_validate_without_publishing(): void
    {
        $command = new ExceptionCompileCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:compile',
                '--check-only',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(true, $decoded['report']['check_only'] ?? null);
        self::assertSame(false, $decoded['report']['published'] ?? null);
        self::assertSame(false, $decoded['report']['verified'] ?? null);

        $app = $this->application();
        self::assertNull($app->make(ExceptionPlanStore::class)->load());
    }

    private function application(): Application
    {
        $app = new Application($this->basePath);
        $bootstrapper = new \Quantum\Bootstrap\Bootstrapper($app);
        $bootstrapper->loadConfiguration();
        $app->boot();

        return $app;
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
