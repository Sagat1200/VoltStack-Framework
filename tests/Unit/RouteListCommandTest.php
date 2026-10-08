<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\RouteListCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class RouteListCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-route-list-command-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Route List',
    'env' => 'testing',
    'providers' => [],
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
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();
\$app->boot();

/** @var Router \$router */
\$router = \$app->make(Router::class);
\$router->get('/', 'HomeController@index');
\$router->post('/health', 'HealthController@store');

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_route_list_command_renders_registered_routes(): void
    {
        $command = new RouteListCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'route:list',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('VoltStack Routes', $output->stdout());
        self::assertStringContainsString('GET', $output->stdout());
        self::assertStringContainsString('POST', $output->stdout());
        self::assertStringContainsString('/', $output->stdout());
        self::assertStringContainsString('/health', $output->stdout());
        self::assertStringContainsString('HomeController@index', $output->stdout());
        self::assertStringContainsString('HealthController@store', $output->stdout());
        self::assertStringContainsString('/_volt/runtime.js', $output->stdout());
        self::assertStringContainsString('/_volt/routes-manifest.json', $output->stdout());
        self::assertStringContainsString('/_volt/action', $output->stdout());
        self::assertStringContainsString('Total routes: 5', $output->stdout());
    }

    public function test_route_list_command_still_lists_runtime_protocol_routes_without_custom_routes(): void
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-route-list-empty-' . uniqid('', true);

        mkdir($basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Route List Empty',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        $escapedBasePath = $this->exportValue($basePath);

        file_put_contents(
            $basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
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

        try {
            $command = new RouteListCommand($basePath);
            $output = new Output();

            $exitCode = $command->handle(
                Input::fromArgv([
                    'volt',
                    'route:list',
                ]),
                $output,
            );

            self::assertSame(0, $exitCode);
            self::assertStringContainsString('VoltStack Routes', $output->stdout());
            self::assertStringContainsString('/_volt/runtime.js', $output->stdout());
            self::assertStringContainsString('/_volt/routes-manifest.json', $output->stdout());
            self::assertStringContainsString('/_volt/action', $output->stdout());
            self::assertStringNotContainsString('HomeController@index', $output->stdout());
            self::assertStringContainsString('Total routes: 3', $output->stdout());
        } finally {
            $this->deleteDirectory($basePath);
        }
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
