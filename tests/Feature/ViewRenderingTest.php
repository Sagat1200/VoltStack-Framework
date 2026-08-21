<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Controllers\Controller;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use Quantum\View\ViewFactory;
use VoltStack\Framework\Application;

final class ViewRenderingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-view-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'home.volt.php',
            <<<'PHP'
<h1><?= e($title) ?></h1>
<p><?= e($message) ?></p>
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_controller_can_return_a_view_response_through_the_kernel(): void
    {
        $app = new Application($this->basePath);
        $router = $app->make(Router::class);
        $router->get('/home', [TestPageController::class, 'show']);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/home'));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('<h1>VoltStack</h1>', $response->content());
        self::assertStringContainsString('<p>Hello from the view layer.</p>', $response->content());
        self::assertStringContainsString('data-volt-runtime="true"', $response->content());
        self::assertMatchesRegularExpression('/<script data-volt-runtime="true" src="\/_volt\/runtime\.js\?v=\d+" defer><\/script>/', $response->content());
        self::assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type']);
    }

    public function test_view_and_response_helpers_are_registered_in_the_container_flow(): void
    {
        $app = new Application($this->basePath);

        self::assertTrue($app->make(ViewFactory::class)->exists('home'));
        self::assertSame('application/json; charset=UTF-8', response()->json(['ok' => true])->headers()['Content-Type']);
    }

    public function test_it_prioritizes_volt_php_views_over_plain_php_views(): void
    {
        $phpFallback = $this->basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'home.php';

        file_put_contents(
            $phpFallback,
            <<<'PHP'
<h1>Fallback</h1>
PHP
        );

        $app = new Application($this->basePath);

        $html = $app->make(ViewFactory::class)->render('home', [
            'title' => 'VoltStack',
            'message' => 'Preferred .volt.php',
        ]);

        self::assertStringContainsString('<h1>VoltStack</h1>', $html);
        self::assertStringContainsString('<p>Preferred .volt.php</p>', $html);
        self::assertStringNotContainsString('<h1>Fallback</h1>', $html);

        unlink($phpFallback);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir()) {
                @rmdir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}

final class TestPageController extends Controller
{
    public function show(): \Quantum\View\View
    {
        return view('home', [
            'title' => 'VoltStack',
            'message' => 'Hello from the view layer.',
        ]);
    }
}
