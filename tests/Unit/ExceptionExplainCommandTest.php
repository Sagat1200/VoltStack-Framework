<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\ExceptionExplainCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class ExceptionExplainCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-exception-explain-command-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Exception Explain',
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

    public function test_exception_explain_command_reports_mapping_and_transport_plan_for_json_fixture(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=validation',
                '--transport=json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('exceptions:explain', $decoded['command'] ?? null);
        self::assertSame('validation', $decoded['report']['fixture']['type'] ?? null);
        self::assertSame('json', $decoded['report']['fixture']['transport'] ?? null);
        self::assertSame('validation.failed', $decoded['report']['mapping']['code'] ?? null);
        self::assertSame('validation.failed', $decoded['report']['mapping']['matched_rule']['id'] ?? null);
        self::assertSame('http.problem_json', $decoded['report']['transport_plan']['target'] ?? null);
        self::assertSame(422, $decoded['report']['transport_plan']['status'] ?? null);
        self::assertSame(false, $decoded['report']['mapping']['used_fallback'] ?? null);
        self::assertSame('application/problem+json', $decoded['report']['rendered']['media_type'] ?? null);
        self::assertSame(true, $decoded['report']['reporting']['ignored_by_policy'] ?? null);
    }

    public function test_exception_explain_command_supports_configuration_fixture_and_cli_json_transport(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=config_invalid',
                '--transport=cli-json',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('config_invalid', $decoded['report']['fixture']['type'] ?? null);
        self::assertSame('configuration.invalid', $decoded['report']['mapping']['code'] ?? null);
        self::assertSame('invalid.argument.configuration', $decoded['report']['mapping']['matched_rule']['id'] ?? null);
        self::assertSame('cli.json', $decoded['report']['transport_plan']['target'] ?? null);
        self::assertSame(78, $decoded['report']['transport_plan']['exit_code'] ?? null);
        self::assertSame('application/json', $decoded['report']['rendered']['media_type'] ?? null);
    }

    public function test_exception_explain_command_reports_unknown_fixtures_cleanly(): void
    {
        $command = new ExceptionExplainCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:explain',
                '--type=unknown-fixture',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(null, $decoded['report'] ?? null);
        self::assertIsString($decoded['error'] ?? null);
        self::assertStringContainsString('Unknown explain fixture', $decoded['error']);
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
