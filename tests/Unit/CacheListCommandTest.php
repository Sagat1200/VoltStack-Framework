<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\CacheListCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class CacheListCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-cache-list-' . uniqid('', true);

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
\$config->set('cache.pools.default', ['store' => 'file', 'prefix' => 'app:', 'default_ttl' => 3600]);
\$config->set('cache.pools.sessions', ['store' => 'file', 'prefix' => 'sess:', 'default_ttl' => 7200]);
\$config->set('cache.pools.metrics', ['store' => 'array', 'prefix' => 'met:']);
\$config->set('cache.stores.array.driver', 'array');

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_lists_pools_in_text_mode(): void
    {
        $command = new CacheListCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:list']),
            $output,
        );

        self::assertSame(0, $exitCode);
        $stdout = $output->stdout();
        self::assertStringContainsString('Pools de cache configurados: 3', $stdout);
        self::assertStringContainsString('default', $stdout);
        self::assertStringContainsString('sessions', $stdout);
        self::assertStringContainsString('metrics', $stdout);
        self::assertStringContainsString('store:', $stdout);
        self::assertStringContainsString('driver:', $stdout);
        self::assertStringContainsString('prefix:', $stdout);
    }

    public function test_it_emits_pools_as_json(): void
    {
        $command = new CacheListCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:list', '--json']),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(3, $decoded['count'] ?? null);
        $pools = $decoded['pools'] ?? [];
        self::assertCount(3, $pools);
        $names = array_column($pools, 'pool');
        self::assertContains('default', $names);
        self::assertContains('sessions', $names);
        self::assertContains('metrics', $names);

        $default = $pools[array_search('default', $names, true)];
        self::assertSame('file', $default['store']);
        self::assertSame('app:', $default['prefix']);
        self::assertSame(3600, $default['default_ttl']);
    }

    public function test_verbose_mode_shows_topology_and_classification(): void
    {
        $command = new CacheListCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:list', '--verbose']),
            $output,
        );

        self::assertSame(0, $exitCode);
        $stdout = $output->stdout();
        self::assertStringContainsString('topology:', $stdout);
        self::assertStringContainsString('classifica.:', $stdout);
        self::assertStringContainsString('default_ttl:', $stdout);
    }

    public function test_it_handles_no_pools_configured(): void
    {
        $emptyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-cache-list-empty-' . uniqid('', true);
        mkdir($emptyBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        $escaped = var_export($emptyBasePath, true);
        file_put_contents(
            $emptyBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php
declare(strict_types=1);
use VoltStack\Framework\Application;
return new Application({$escaped});
PHP
        );

        $command = new CacheListCommand($emptyBasePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:list']),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No hay pools de cache configurados.', $output->stdout());

        $this->deleteDirectory($emptyBasePath);
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
