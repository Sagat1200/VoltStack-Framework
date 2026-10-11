<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Http\Response;
use Quantum\Transport\Contracts\TransportEmitterInterface;
use Quantum\Transport\Testing\InMemoryTransportEmitter;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Adapters\FrankenPhpRuntimeAdapter;
use VoltStack\Runtime\Context\RuntimeContext;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeConfiguration;

final class FrankenPhpRuntimeAdapterTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        FrankenPhpTestSuccessKernel::$handledPaths = [];
        FrankenPhpTestThrowingKernel::$calls = 0;

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-frankenphp-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack FrankenPHP',
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

    public function test_native_loop_fails_closed_when_runtime_not_available(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new FrankenPhpTestSuccessKernel());
        $app->instance(TransportEmitterInterface::class, new InMemoryTransportEmitter());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::frankenphp(3, null);

        $adapter = new FrankenPhpRuntimeAdapter(
            nativeLoopInvoker: static fn (callable $handler): bool => true,
            nativeAvailabilityResolver: static fn (): bool => false,
        );

        $this->expectException(RuntimeAdapterException::class);
        $this->expectExceptionMessage('FrankenPHP native worker mode requires frankenphp_handle_request()');

        $adapter->run($plan, $factory, $configuration);
    }

    public function test_native_loop_processes_multiple_requests_with_changing_superglobals(): void
    {
        $paths = ['/franken-1', '/franken-2', '/franken-3'];
        $iteration = 0;

        $invoker = function (callable $handler) use ($paths, &$iteration): bool {
            if ($iteration >= count($paths)) {
                return false;
            }

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = $paths[$iteration];
            $iteration++;

            $handler();

            return true;
        };

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new FrankenPhpTestSuccessKernel());
        $app->instance(TransportEmitterInterface::class, new InMemoryTransportEmitter());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::frankenphp(10, null);

        $adapter = new FrankenPhpRuntimeAdapter(
            nativeLoopInvoker: $invoker,
            nativeAvailabilityResolver: static fn (): bool => true,
        );

        $exitCode = $adapter->run($plan, $factory, $configuration);

        self::assertSame(0, $exitCode);
        self::assertSame($paths, FrankenPhpTestSuccessKernel::$handledPaths);
        self::assertSame(3, $iteration);
    }

    public function test_native_loop_terminates_on_kernel_exception_and_returns_one(): void
    {
        $iteration = 0;

        $invoker = function (callable $handler) use (&$iteration): bool {
            $iteration++;
            if ($iteration > 1) {
                return false;
            }

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/franken-boom';

            $handler();

            return true;
        };

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new FrankenPhpTestThrowingKernel());
        $app->instance(TransportEmitterInterface::class, new InMemoryTransportEmitter());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::frankenphp(3, null);

        $adapter = new FrankenPhpRuntimeAdapter(
            nativeLoopInvoker: $invoker,
            nativeAvailabilityResolver: static fn (): bool => true,
        );

        $exitCode = $adapter->run($plan, $factory, $configuration);

        self::assertSame(1, $exitCode);
        self::assertSame(1, FrankenPhpTestThrowingKernel::$calls);
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

final class FrankenPhpTestSuccessKernel implements KernelContract
{
    /**
     * @var list<string>
     */
    public static array $handledPaths = [];

    public function handle(\Quantum\Http\Request $request): Response
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

final class FrankenPhpTestThrowingKernel implements KernelContract
{
    public static int $calls = 0;

    public function handle(\Quantum\Http\Request $request): Response
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
