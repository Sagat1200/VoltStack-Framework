<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\ExceptionValidateCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class ExceptionValidateCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-exception-validate-command-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Exception Validate',
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

        $this->writeBootstrapApp();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_exception_validate_command_reports_valid_configuration(): void
    {
        $command = new ExceptionValidateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:validate',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('exceptions:validate', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame('development', $decoded['report']['environment'] ?? null);
        self::assertSame('sapi', $decoded['report']['runtime'] ?? null);
        self::assertSame([1], $decoded['report']['spa_versions'] ?? null);
        self::assertIsArray($decoded['report']['config'] ?? null);
    }

    public function test_exception_validate_command_reports_compilation_errors_cleanly(): void
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

        $command = new ExceptionValidateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:validate',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['passed'] ?? null);
        self::assertIsString($decoded['report']['error'] ?? null);
        self::assertStringContainsString('exceptions.debug', $decoded['report']['error']);
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
