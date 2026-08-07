<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Compilation\Contracts\ArtifactStoreInterface;
use Quantum\Compilation\Contracts\CompiledControllerFactoryInterface;
use Quantum\Console\Commands\CompileClearCommand;
use Quantum\Console\Commands\CompileCommand;
use Quantum\Console\Commands\CompileWarmupCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

final class CompilationCommandsTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-compile-cmd-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'routes', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'env' => 'production',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'controller_compilation.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'artifacts' => [
        'format' => 'php',
    ],
    'warmup' => [
        'hot_routes' => [
            \VoltStack\Test\Unit\CompileCommandHotController::class . '@dashboard',
        ],
    ],
    'cache' => [
        'worker' => [
            'max_artifacts' => 64,
        ],
    ],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use Quantum\Routing\Router;
use VoltStack\Test\Unit\CompileCommandHomeController;
use VoltStack\Test\Unit\CompileCommandAboutController;
use VoltStack\Test\Unit\CompileCommandInvokableStub;

return static function (Router $router): void {
    $router->get('/', CompileCommandHomeController::class . '@index')
        ->name('home');

    $router->get('/about', CompileCommandAboutController::class . '@show')
        ->name('about');

    $router->get('/ping', CompileCommandInvokableStub::class)
        ->name('ping');
};
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
\$bootstrapper->loadRoutes(__DIR__ . '/../routes/web.php');

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_compile_command_discovers_and_compiles_routes_into_build(): void
    {
        $command = new CompileCommand($this->basePath);
        $output = new Output();

        $exit = $command->handle(
            Input::fromArgv([
                'volt',
                'controller-compiler',
                '--verbose',
            ]),
            $output,
        );

        $stdout = $output->stdout();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Build creado:', $stdout);
        self::assertMatchesRegularExpression('/Compilando \d+ controlador\(es\)\.\.\./', $stdout);
        self::assertStringContainsString('[OK]', $stdout);
        self::assertMatchesRegularExpression('/Resultado: \d+ OK \/ 0 FAIL \/ 0 SKIP/', $stdout);
        self::assertStringContainsString('Build activado:', $stdout);
        self::assertStringContainsString('¡Compilación de controladores completada con éxito!', $stdout);

        $app = $this->bootstrappedApplication();
        $store = $app->make(ArtifactStoreInterface::class);
        $current = $store->currentBuild();

        self::assertNotNull($current);
        self::assertTrue($current->active);

        $router = $app->make(Router::class);
        $routes = method_exists($router, 'routes') ? $router->routes() : [];
        self::assertGreaterThanOrEqual(3, count($routes));

        $factory = $app->make(CompiledControllerFactoryInterface::class);
        $homeKey = $factory->makeKey(new ControllerDefinition(CompileCommandHomeController::class . '@index'));
        $aboutKey = $factory->makeKey(new ControllerDefinition(CompileCommandAboutController::class . '@show'));
        $pingKey = $factory->makeKey(new ControllerDefinition(CompileCommandInvokableStub::class));

        self::assertTrue($store->exists($homeKey), 'Home compiled artifact must exist after activation.');
        self::assertTrue($store->exists($aboutKey), 'About compiled artifact must exist after activation.');
        self::assertTrue($store->exists($pingKey), 'Invokable ping compiled artifact must exist after activation.');
    }

    public function test_compile_command_no_routes_exits_cleanly_with_suggestion(): void
    {
        $emptyPath = $this->createEmptySkeleton();
        $command = new CompileCommand($emptyPath);
        $output = new Output();

        $exit = $command->handle(
            Input::fromArgv([
                'volt',
                'controller-compiler',
            ]),
            $output,
        );

        self::assertSame(0, $exit);
        self::assertStringContainsString('Build creado:', $output->stdout());
        self::assertMatchesRegularExpression('/Compilando \d+ controlador\(es\)\.\.\./', $output->stdout());

        $this->deleteDirectory($emptyPath);
    }

    public function test_compile_command_no_activate_leaves_build_inactive(): void
    {
        $command = new CompileCommand($this->basePath);
        $output = new Output();

        $exit = $command->handle(
            Input::fromArgv([
                'volt',
                'controller-compiler',
                '--no-activate',
            ]),
            $output,
        );

        self::assertSame(0, $exit);
        self::assertStringContainsString('Build completado pero NO activado', $output->stdout());

        $app = $this->bootstrappedApplication();
        $store = $app->make(ArtifactStoreInterface::class);

        self::assertNull($store->currentBuild(), 'Using --no-activate, there must be no active build.');
        self::assertGreaterThanOrEqual(1, count($store->listBuilds()));
    }

    public function test_compile_clear_command_removes_builds_and_worker_cache(): void
    {
        $compile = new CompileCommand($this->basePath);
        $compileOutput = new Output();
        $compile->handle(
            Input::fromArgv(['volt', 'controller-compiler']),
            $compileOutput,
        );

        $app = $this->bootstrappedApplication();
        $store = $app->make(ArtifactStoreInterface::class);
        self::assertNotNull($store->currentBuild());

        $clear = new CompileClearCommand($this->basePath);
        $clearOutput = new Output();
        $exit = $clear->handle(
            Input::fromArgv([
                'volt',
                'controller-compiler:clear',
                '--verbose',
            ]),
            $clearOutput,
        );

        self::assertSame(0, $exit);
        $stdout = $clearOutput->stdout();
        self::assertStringContainsString('Builds existentes antes de limpiar: 1', $stdout);
        self::assertStringContainsString('Builds eliminados:', $stdout);
        self::assertStringContainsString('Cache de compilación de controladores limpiado correctamente.', $stdout);

        $app2 = $this->bootstrappedApplication();
        $store2 = $app2->make(ArtifactStoreInterface::class);
        self::assertNull($store2->currentBuild());
    }

    public function test_compile_warmup_command_compiles_hot_routes_from_config(): void
    {
        $command = new CompileWarmupCommand($this->basePath);
        $output = new Output();

        $exit = $command->handle(
            Input::fromArgv([
                'volt',
                'controller-compiler:warmup',
                '--verbose',
            ]),
            $output,
        );

        $stdout = $output->stdout();
        self::assertSame(0, $exit, ($output->stderr() ?: '') . "\n" . $stdout);
        self::assertStringContainsString('Warmup de 1 ruta(s) hot...', $stdout);
        self::assertStringContainsString('Nuevo build de warmup:', $stdout);
        self::assertStringContainsString('[WARMUP OK]', $stdout);
        self::assertStringContainsString('Warmup: 1 OK / 0 FAIL', $stdout);
        self::assertStringContainsString('Build warmup activado:', $stdout);
        self::assertStringContainsString('Warmup completado.', $stdout);

        $app = $this->bootstrappedApplication();
        $store = $app->make(ArtifactStoreInterface::class);
        $factory = $app->make(CompiledControllerFactoryInterface::class);

        $hotKey = $factory->makeKey(new ControllerDefinition(CompileCommandHotController::class . '@dashboard'));
        self::assertTrue($store->exists($hotKey));
    }

    public function test_warmup_command_reports_missing_hot_routes(): void
    {
        $emptyPath = $this->createEmptySkeleton();
        $command = new CompileWarmupCommand($emptyPath);
        $output = new Output();

        $exit = $command->handle(
            Input::fromArgv(['volt', 'compile:warmup']),
            $output,
        );

        self::assertSame(0, $exit);
        self::assertStringContainsString('No hay rutas hot configuradas', $output->stdout());

        $this->deleteDirectory($emptyPath);
    }

    private function createEmptySkeleton(): string
    {
        $empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-compile-cmd-empty-' . uniqid('', true);

        mkdir($empty . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($empty . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir($empty . DIRECTORY_SEPARATOR . 'routes', 0777, true);

        file_put_contents($empty . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', "<?php\ndeclare(strict_types=1);\nreturn ['env'=>'production','providers'=>[]];\n");
        file_put_contents($empty . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'controller_compilation.php', "<?php\ndeclare(strict_types=1);\nreturn ['enabled'=>true,'warmup'=>['hot_routes'=>[]]];\n");
        file_put_contents($empty . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php', "<?php\ndeclare(strict_types=1);\nreturn static function (\\Quantum\\Routing\\Router \$r): void {};\n");

        $escaped = var_export($empty, true);
        file_put_contents($empty . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php', <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use VoltStack\Framework\Application;

\$app = new Application({$escaped});
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();
\$app->boot();
\$bootstrapper->loadRoutes(__DIR__ . '/../routes/web.php');

return \$app;
PHP
        );

        return $empty;
    }

    private function bootstrappedApplication(): Application
    {
        return require $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $subPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_link($subPath) || is_file($subPath)) {
                @unlink($subPath);
            } else {
                $this->deleteDirectory($subPath);
            }
        }

        @rmdir($path);
    }
}

final class CompileCommandHomeController
{
    public function index(): string
    {
        return 'home';
    }
}

final class CompileCommandAboutController
{
    public function show(): string
    {
        return 'about';
    }
}

final class CompileCommandInvokableStub
{
    public function __invoke(): string
    {
        return 'pong';
    }
}

final class CompileCommandHotController
{
    public function dashboard(): string
    {
        return 'dashboard';
    }
}