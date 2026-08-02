<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use Quantum\Validation\Validator;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;

final class ExceptionHandlingTest extends TestCase
{
    private string $basePath;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-exception-handling-' . uniqid('', true);

        if (! mkdir($concurrentDirectory = $this->basePath, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create test directory [%s].', $this->basePath));
        }

        $this->app = new Application($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_renders_a_html_404_response_for_missing_routes(): void
    {
        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/missing'));

        self::assertSame(404, $response->statusCode());
        self::assertStringContainsString('Page Not Found', $response->content());
        self::assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type']);
    }

    public function test_it_renders_json_validation_errors_when_the_request_expects_json(): void
    {
        $router = $this->app->make(Router::class);
        $router->post('/validate', function (Validator $validator): array {
            $validator->validate([
                'title' => '',
            ], [
                'title' => ['required', 'string', 'min:3'],
            ]);

            return ['ok' => true];
        });

        $response = $this->app->make(KernelContract::class)->handle(Request::create(
            '/validate',
            'POST',
            [],
            ['title' => ''],
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
        ));

        self::assertSame(422, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('The given data was invalid.', $payload['message']);
        self::assertArrayHasKey('title', $payload['errors']);
    }

    public function test_it_renders_a_json_405_response_with_allow_header_when_the_request_expects_json(): void
    {
        $router = $this->app->make(Router::class);
        $router->post('/submit', fn(): array => ['ok' => true]);

        $response = $this->app->make(KernelContract::class)->handle(Request::create(
            '/submit',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
        ));

        self::assertSame(405, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame('POST, OPTIONS', $response->headers()['Allow']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Method Not Allowed', $payload['message']);
    }

    public function test_it_keeps_volt_navigation_errors_as_html_responses(): void
    {
        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            '/missing',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_X_REQUESTED_WITH' => 'VoltStack',
                'HTTP_X_VOLT_NAVIGATE' => 'true',
            ],
        ));

        self::assertSame(404, $response->statusCode());
        self::assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertStringContainsString('Page Not Found', $response->content());
    }

    public function test_it_exposes_a_controller_error_code_when_controller_dispatch_fails(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/broken-controller', TestBrokenController::class);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/broken-controller'));

        self::assertSame(500, $response->statusCode());
        self::assertSame('controller.method_not_found', $response->headers()['X-Volt-Error-Code'] ?? null);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}

final class TestBrokenController
{
}
