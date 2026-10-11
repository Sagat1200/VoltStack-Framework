<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Transport\Contracts\TransportEmitterInterface;
use Quantum\Transport\Testing\InMemoryTransportEmitter;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Adapters\RoadRunnerNativeWorkerInterface;
use VoltStack\Runtime\Adapters\RoadRunnerRuntimeAdapter;
use VoltStack\Runtime\Context\RuntimeContext;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeConfiguration;

final class RoadRunnerRuntimeAdapterTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        RoadRunnerTestSuccessKernel::$handledPaths = [];

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-roadrunner-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack RoadRunner',
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

    public function test_capabilities_without_native_worker_reports_simulated_http(): void
    {
        $adapter = new RoadRunnerRuntimeAdapter();
        $capabilities = $adapter->capabilities();

        self::assertFalse($capabilities->nativeHttp());
        self::assertFalse($capabilities->nativeIntegrationVerified());
    }

    public function test_capabilities_with_native_worker_reports_native_http(): void
    {
        $worker = $this->createMock(RoadRunnerNativeWorkerInterface::class);
        $adapter = new RoadRunnerRuntimeAdapter($worker);
        $capabilities = $adapter->capabilities();

        self::assertTrue($capabilities->nativeHttp());
        self::assertTrue($capabilities->nativeIntegrationVerified());
    }

    public function test_run_fails_closed_without_request_source_and_without_native_worker(): void
    {
        $app = new Application($this->basePath);
        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::roadrunner(3, null);

        $adapter = new RoadRunnerRuntimeAdapter();

        $this->expectException(RuntimeAdapterException::class);
        $this->expectExceptionMessage('RoadRunner runtime adapter requires either an in-memory request source or an injected RoadRunnerNativeWorkerInterface.');

        $adapter->run($plan, $factory, $configuration);
    }

    public function test_run_uses_native_worker_when_no_request_source_provided(): void
    {
        $requests = [
            Request::create('/rr-1', 'GET'),
            Request::create('/rr-2', 'GET'),
        ];

        $worker = new RoadRunnerTestNativeWorker($requests);

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new RoadRunnerTestSuccessKernel());
        $emitter = new InMemoryTransportEmitter();
        $app->instance(TransportEmitterInterface::class, $emitter);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::roadrunner(count($requests), null);

        $adapter = new RoadRunnerRuntimeAdapter($worker);
        $exitCode = $adapter->run($plan, $factory, $configuration);

        self::assertSame(0, $exitCode);
        self::assertSame(['/rr-1', '/rr-2'], RoadRunnerTestSuccessKernel::$handledPaths);
        self::assertCount(2, $worker->responses);
        self::assertSame('ok:/rr-1', $worker->responses[0]->content());
        self::assertSame('ok:/rr-2', $worker->responses[1]->content());
    }

    public function test_run_with_request_source_delegates_to_sequential_loop(): void
    {
        $requests = [Request::create('/src-loop', 'GET')];

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new RoadRunnerTestSuccessKernel());
        $emitter = new InMemoryTransportEmitter();
        $app->instance(TransportEmitterInterface::class, $emitter);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::roadrunner(1, $requests);

        $adapter = new RoadRunnerRuntimeAdapter();
        $exitCode = $adapter->run($plan, $factory, $configuration);

        self::assertSame(0, $exitCode);
        self::assertSame(['/src-loop'], RoadRunnerTestSuccessKernel::$handledPaths);
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

final class RoadRunnerTestNativeWorker implements RoadRunnerNativeWorkerInterface
{
    /**
     * @param list<Request> $requests
     */
    public function __construct(
        private array $requests,
    ) {
    }

    /** @var list<Response> */
    public array $responses = [];

    public function waitRequest(): ?Request
    {
        if ($this->requests === []) {
            return null;
        }

        return array_shift($this->requests);
    }

    public function respond(Response $response): void
    {
        $this->responses[] = $response;
    }
}

final class RoadRunnerTestSuccessKernel implements KernelContract
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
