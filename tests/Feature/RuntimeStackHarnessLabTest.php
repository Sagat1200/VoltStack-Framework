<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RuntimeStackHarnessLabTest extends TestCase
{
    private static string $skeletonBasePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$skeletonBasePath = self::locateSkeletonBasePath();

        require_once self::$skeletonBasePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    public function test_runtime_lab_harness_summary_page_shows_bindings_and_smoke_markers(): void
    {
        $html = $this->handleSkeletonRequest('/runtime-lab-harness');
        self::assertSame(200, $html->statusCode(), $html->content());
        self::assertStringContainsString('Runtime Stack Harness', $html->content());
        self::assertStringContainsString('data-runtime-check="transport-kernel-binding"', $html->content());
        self::assertStringContainsString('data-runtime-check="smoke-transport-send"', $html->content());
        self::assertStringContainsString('data-runtime-check="smoke-emission-started"', $html->content());
        self::assertStringContainsString('HttpKernelTransportKernel', $html->content());
    }

    public function test_runtime_lab_harness_json_endpoint_exposes_stack_contracts(): void
    {
        $response = $this->handleSkeletonRequest('/runtime-lab-harness?section=json');
        self::assertSame(200, $response->statusCode(), $response->content());

        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('runtime-stack-harness', $payload['lab'] ?? null);
        self::assertIsArray($payload['smoke'] ?? null);
        self::assertNotFalse($payload['smoke']['kernel_bound'] ?? false);
        self::assertNotFalse($payload['smoke']['transport_manager_bound'] ?? false);
        self::assertNotFalse($payload['smoke']['transport_send'] ?? false);
        self::assertNull($payload['smoke']['last_error'] ?? null);
    }

    public function test_runtime_lab_harness_probe_request_routes_to_controller_and_returns_200(): void
    {
        $home = $this->handleSkeletonRequest('/');
        self::assertSame(200, $home->statusCode(), $home->content());

        $probe = $this->handleSkeletonRequest('/runtime-lab-harness?section=probe');
        self::assertSame(200, $probe->statusCode(), $probe->content());
        self::assertStringContainsString('runtime-lab-harness', $probe->content());
    }

    private function handleSkeletonRequest(string $path): Response
    {
        $app = new Application(self::$skeletonBasePath);
        $bootstrapper = new Bootstrapper($app);
        $bootstrapper->loadConfiguration();

        foreach ((array) $app->config('app.providers', []) as $provider) {
            $app->register($provider);
        }

        $app->boot();

        $router = $app->make(Router::class);

        $routes = require self::$skeletonBasePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';
        $routes();

        return $app->make(\Quantum\HttpKernel\HttpKernel::class)->handle(Request::create($path));
    }

    private static function locateSkeletonBasePath(): string
    {
        $candidates = [
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app-skeleton',
            dirname(__DIR__, 5),
        ];

        foreach ($candidates as $candidate) {
            if (
                is_file($candidate . DIRECTORY_SEPARATOR . 'composer.json') &&
                is_dir($candidate . DIRECTORY_SEPARATOR . 'app') &&
                is_dir($candidate . DIRECTORY_SEPARATOR . 'routes')
            ) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No se localizo el app-skeleton para el harness.');
    }
}
