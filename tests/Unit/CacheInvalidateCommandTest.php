<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\CacheInvalidateCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class CacheInvalidateCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-cache-invalidate-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);

        $escapedBasePath = var_export($this->basePath, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$config = \$app->make(ConfigRepository::class);
\$config->set('cache.default_pool', 'default');
\$config->set('cache.pools.default', ['store' => 'file', 'prefix' => 'app:', 'default_ttl' => 3600]);
\$config->set('cache.pools.sessions', ['store' => 'file', 'prefix' => 'sess:', 'default_ttl' => 7200]);

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_requires_tags_option(): void
    {
        $command = new CacheInvalidateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:invalidate']),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--tags', $output->stderr());
    }

    public function test_dry_run_emits_plan_in_text_mode(): void
    {
        $command = new CacheInvalidateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:invalidate', '--tags=user,profile']),
            $output,
        );

        self::assertSame(0, $exitCode);
        $stdout = $output->stdout();
        self::assertStringContainsString('Plan de invalidacion (dry-run)', $stdout);
        self::assertStringContainsString('cache.invalidation.plan.v1', $stdout);
        self::assertStringContainsString('user', $stdout);
        self::assertStringContainsString('profile', $stdout);
        self::assertStringContainsString('dry-run', $stdout);
    }

    public function test_dry_run_emits_plan_as_json(): void
    {
        $command = new CacheInvalidateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:invalidate', '--tags=user,profile', '--json']),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('cache.invalidation.plan.v1', $decoded['schema_version'] ?? null);
        self::assertSame('invalidate_tags', $decoded['action'] ?? null);
        self::assertTrue($decoded['dry_run'] ?? false);
        self::assertSame(['profile', 'user'], $decoded['target']['tags'] ?? null);
        self::assertContains('scope_resolved', $decoded['effects'] ?? []);
    }

    public function test_apply_executes_real_invalidation(): void
    {
        $command = new CacheInvalidateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:invalidate', '--tags=user,profile', '--apply']),
            $output,
        );

        self::assertSame(0, $exitCode);
        $stdout = $output->stdout();
        self::assertStringContainsString('Invalidacion de tags', $stdout);
        self::assertStringContainsString('applied: si', $stdout);
        self::assertStringContainsString('Tags invalidados correctamente.', $stdout);
    }

    public function test_apply_json_reports_applied(): void
    {
        $command = new CacheInvalidateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:invalidate', '--tags=user', '--pool=sessions', '--apply', '--json']),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('sessions', $decoded['pool'] ?? null);
        self::assertSame(['user'], $decoded['tags'] ?? null);
        self::assertTrue($decoded['applied'] ?? false);
        self::assertSame('applied', $decoded['effect'] ?? null);
        self::assertArrayHasKey('operation_id', $decoded);
        self::assertArrayHasKey('plan', $decoded);
    }

    public function test_it_rejects_empty_tags(): void
    {
        $command = new CacheInvalidateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:invalidate', '--tags=']),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('tags', $output->stderr());
    }

    public function test_it_reports_error_for_unknown_pool(): void
    {
        $command = new CacheInvalidateCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:invalidate', '--tags=user', '--pool=nonexistent']),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No se pudo planificar', $output->stderr());
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
