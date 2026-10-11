<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Http\Request;
use Quantum\Http\Response;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Adapters\OpenSwooleNativeServerInterface;
use VoltStack\Runtime\Adapters\OpenSwooleRuntimeAdapter;
use VoltStack\Runtime\Context\RuntimeContext;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeConfiguration;

final class OpenSwooleRuntimeAdapterTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        OpenSwooleTestSuccessKernel::$handledPaths = [];
        OpenSwooleTestThrowingKernel::$calls = 0;

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-openswoole-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack OpenSwoole',
    'env' => 'testing',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        RuntimeContext::setCurrent(null);
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_capabilities_without_native_server_reports_simulated_http(): void
    {
        $adapter = new OpenSwooleRuntimeAdapter();
        $capabilities = $adapter->capabilities();

        self::assertFalse($capabilities->nativeHttp());
        self::assertFalse($capabilities->nativeIntegrationVerified());
    }

    public function test_capabilities_with_native_server_reports_native_http(): void
    {
        $server = $this->createMock(OpenSwooleNativeServerInterface::class);
        $adapter = new OpenSwooleRuntimeAdapter($server);
        $capabilities = $adapter->capabilities();

        self::assertTrue($capabilities->nativeHttp());
        self::assertTrue($capabilities->nativeIntegrationVerified());
    }

    public function test_run_fails_closed_without_request_source_and_without_native_server(): void
    {
        $app = new Application($this->basePath);
        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::openswoole(3, null);

        $adapter = new OpenSwooleRuntimeAdapter();

        $this->expectException(RuntimeAdapterException::class);
        $this->expectExceptionMessage('OpenSwoole runtime adapter requires either an in-memory request source or an injected OpenSwooleNativeServerInterface.');

        $adapter->run($plan, $factory, $configuration);
    }

    public function test_run_uses_native_server_when_no_request_source_provided(): void
    {
        $requests = [
            Request::create('/swoole-1', 'GET'),
            Request::create('/swoole-2', 'GET'),
        ];
        $server = new OpenSwooleTestNativeServer($requests);

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new OpenSwooleTestSuccessKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::openswoole(count($requests), null);

        $adapter = new OpenSwooleRuntimeAdapter($server);
        $exitCode = $adapter->run($plan, $factory, $configuration);

        self::assertSame(0, $exitCode);
        self::assertSame(['/swoole-1', '/swoole-2'], OpenSwooleTestSuccessKernel::$handledPaths);
        self::assertCount(2, $server->sentResponses);
        self::assertSame('ok:/swoole-1', $server->sentResponses[0]->content());
        self::assertSame('ok:/swoole-2', $server->sentResponses[1]->content());
    }

    public function test_run_terminates_on_kernel_exception_and_returns_one(): void
    {
        $requests = [Request::create('/swoole-boom', 'GET')];
        $server = new OpenSwooleTestNativeServer($requests);

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new OpenSwooleTestThrowingKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::openswoole(3, null);

        $adapter = new OpenSwooleRuntimeAdapter($server);
        $exitCode = $adapter->run($plan, $factory, $configuration);

        self::assertSame(1, $exitCode);
        self::assertSame(1, OpenSwooleTestThrowingKernel::$calls);
    }

    public function test_run_with_request_source_delegates_to_sequential_loop(): void
    {
        $requests = [Request::create('/src-loop', 'GET')];

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new OpenSwooleTestSuccessKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::openswoole(1, $requests);

        $adapter = new OpenSwooleRuntimeAdapter();
        $exitCode = $adapter->run($plan, $factory, $configuration);

        self::assertSame(0, $exitCode);
        self::assertSame(['/src-loop'], OpenSwooleTestSuccessKernel::$handledPaths);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $children = scandir($path);
        if ($children === false) {
            return;
        }

        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }

            $childPath = $path . DIRECTORY_SEPARATOR . $child;
            if (is_dir($childPath)) {
                $this->deleteDirectory($childPath);
            } else {
                unlink($childPath);
            }
        }

        rmdir($path);
    }
}

final class OpenSwooleTestNativeServer implements OpenSwooleNativeServerInterface
{
    /**
     * @param list<Request> $requests
     */
    public function __construct(
        private array $requests,
    ) {
    }

    /** @var list<Response> */
    public array $sentResponses = [];

    public function start(callable $onRequest): void
    {
        foreach ($this->requests as $request) {
            $response = $onRequest($request);

            if ($response === null) {
                break;
            }

            $this->sentResponses[] = $response;
        }
    }
}

final class OpenSwooleTestSuccessKernel implements KernelContract
{
    /**
     * @var list<string>
     */
    public static array $handledPaths = [];

    public function handle(Request $request): Response
    {
        self::$handledPaths[] = $request->path();

        return new Response('ok:' . $request->path());
    }

    public function setMiddlewares(array $middlewares): void
    {
    }

    public function pushMiddleware(callable|string|\Quantum\HttpKernel\Contracts\MiddlewareInterface $middleware): void
    {
    }
}

final class OpenSwooleTestThrowingKernel implements KernelContract
{
    public static int $calls = 0;

    public function handle(Request $request): Response
    {
        self::$calls++;

        throw new RuntimeException('kernel failed intentionally');
    }

    public function setMiddlewares(array $middlewares): void
    {
    }

    public function pushMiddleware(callable|string|\Quantum\HttpKernel\Contracts\MiddlewareInterface $middleware): void
    {
    }
}
