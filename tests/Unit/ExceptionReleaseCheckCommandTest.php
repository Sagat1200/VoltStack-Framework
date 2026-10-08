<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\ExceptionReleaseCheckCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Exceptions\Compilation\ExceptionPlanStore;
use VoltStack\Framework\Application;

final class ExceptionReleaseCheckCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-exception-release-check-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Exception Release Check',
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

    public function test_exception_release_check_fails_by_default_when_no_published_plan_exists(): void
    {
        $command = new ExceptionReleaseCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:release-check',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['report']['passed'] ?? null);
        self::assertContains(
            'No hay un plan de excepciones publicado.',
            $decoded['report']['violations'] ?? [],
        );
    }

    public function test_exception_release_check_passes_when_published_plan_matches_effective_plan(): void
    {
        $this->publishCurrentPlan();

        $command = new ExceptionReleaseCheckCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:release-check',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(true, $decoded['report']['passed'] ?? null);
        self::assertSame(true, $decoded['report']['status']['published_matches_effective'] ?? null);
        self::assertSame(true, $decoded['report']['status']['published_compatible'] ?? null);
        self::assertSame([], $decoded['report']['violations'] ?? null);
    }

    public function test_exception_release_check_detects_drift_and_can_relax_it(): void
    {
        $this->publishCurrentPlan();
        $this->writeBootstrapApp(mutateAfterBoot: true);

        $command = new ExceptionReleaseCheckCommand($this->basePath);

        $failingOutput = new Output();
        $failingExitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:release-check',
                '--json',
            ]),
            $failingOutput,
        );

        self::assertSame(1, $failingExitCode);

        $failingDecoded = json_decode(trim($failingOutput->stdout()), true);
        self::assertIsArray($failingDecoded);
        self::assertContains(
            'El plan de excepciones efectivo difiere del plan publicado.',
            $failingDecoded['report']['violations'] ?? [],
        );

        $relaxedOutput = new Output();
        $relaxedExitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'exceptions:release-check',
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
