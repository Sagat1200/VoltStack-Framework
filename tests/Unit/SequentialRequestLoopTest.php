<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Transport\Contracts\ResponseTransportManagerInterface;
use Quantum\Transport\Contracts\TransportEmitterInterface;
use Quantum\Transport\Enums\TransportStatus;
use Quantum\Transport\Response\TransportResponse;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportResult;
use Quantum\Transport\Testing\InMemoryTransportEmitter;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeConfiguration;
use VoltStack\Runtime\SequentialRequestLoop;
use VoltStack\Runtime\WorkerSession;

final class SequentialRequestLoopTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        SequentialRequestLoopTestSuccessKernel::$handledPaths = [];
        SequentialRequestLoopTestThrowingKernel::$calls = 0;

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-sequential-loop-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Sequential Loop',
    'env' => 'testing',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_run_for_source_driver_fails_closed_without_request_source(): void
    {
        $app = new Application($this->basePath);
        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::roadrunner(3, null);

        $this->expectException(RuntimeAdapterException::class);
        $this->expectExceptionMessage('RoadRunner runtime adapter requires an in-memory request source until a native HTTP bridge is implemented.');

        SequentialRequestLoop::runForSourceDriver(
            driver: 'roadrunner',
            plan: $plan,
            factory: $factory,
            configuration: $configuration,
            driverLabel: 'RoadRunner',
        );
    }

    public function test_run_for_source_driver_preserves_driver_label_in_closed_message(): void
    {
        $cases = [
            ['roadrunner', 'RoadRunner'],
            ['openswoole', 'OpenSwoole'],
        ];

        $app = new Application($this->basePath);
        $factory = $app->make(WorkerFactoryInterface::class);
        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        foreach ($cases as [$driver, $driverLabel]) {
            $configuration = new RuntimeConfiguration(driver: $driver, maxRequests: 1, drainOnTerminate: true, requestSource: null);

            try {
                SequentialRequestLoop::runForSourceDriver(
                    driver: $driver,
                    plan: $plan,
                    factory: $factory,
                    configuration: $configuration,
                    driverLabel: $driverLabel,
                );
                self::fail(sprintf('Expected exception for driver %s', $driverLabel));
            } catch (RuntimeAdapterException $exception) {
                self::assertStringContainsString(sprintf('%s runtime adapter requires an in-memory request source until a native HTTP bridge is implemented.', $driverLabel), $exception->getMessage());
            }
        }
    }

    public function test_run_for_source_driver_loops_until_max_requests_is_reached_and_returns_zero(): void
    {
        $maxRequests = 2;
        $requests = [
            Request::create('/a', 'GET'),
            Request::create('/b', 'GET'),
        ];

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new SequentialRequestLoopTestSuccessKernel());
        $emitter = new InMemoryTransportEmitter();
        $app->instance(TransportEmitterInterface::class, $emitter);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::roadrunner($maxRequests, $requests);

        $exitCode = SequentialRequestLoop::runForSourceDriver(
            driver: 'roadrunner',
            plan: $plan,
            factory: $factory,
            configuration: $configuration,
            driverLabel: 'RoadRunner',
        );

        self::assertSame(0, $exitCode);
        self::assertSame(['/a', '/b'], SequentialRequestLoopTestSuccessKernel::$handledPaths);
        $emitted = $emitter->emitted();
        self::assertCount(2, $emitted);
        foreach ($emitted as $record) {
            self::assertArrayHasKey('runtime.driver', $record['context']->attributes);
            self::assertSame('roadrunner', $record['context']->attributes['runtime.driver']);
            self::assertArrayHasKey('runtime.worker_id', $record['context']->attributes);
            self::assertArrayHasKey('runtime.handled_requests', $record['context']->attributes);
        }
    }

    public function test_run_for_source_driver_stops_on_terminate_disposition_with_exit_code_one(): void
    {
        $requests = [
            Request::create('/a', 'GET'),
            Request::create('/b', 'GET'),
        ];

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new SequentialRequestLoopTestThrowingKernel());
        $emitter = new InMemoryTransportEmitter();
        $app->instance(TransportEmitterInterface::class, $emitter);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::openswoole(3, $requests);

        $exitCode = SequentialRequestLoop::runForSourceDriver(
            driver: 'openswoole',
            plan: $plan,
            factory: $factory,
            configuration: $configuration,
            driverLabel: 'OpenSwoole',
        );

        self::assertSame(1, $exitCode);
        self::assertSame(1, SequentialRequestLoopTestThrowingKernel::$calls);
    }

    public function test_run_for_source_driver_terminates_on_emit_failure_and_returns_one(): void
    {
        $requests = [Request::create('/boom', 'GET')];

        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new SequentialRequestLoopTestSuccessKernel());
        $failingTransport = new class implements ResponseTransportManagerInterface {
            /** @var list<array{response: TransportResponse, context: TransportContext}> */
            public array $records = [];

            public function send(\Quantum\Transport\Contracts\ResponseInterface $response, TransportContext $context): TransportResult
            {
                $this->records[] = ['response' => $response, 'context' => $context];

                return new TransportResult(
                    status: TransportStatus::Failed,
                    bytesEmitted: 0,
                    completed: false,
                    connectionClosed: true,
                    emissionStarted: true,
                    exception: null,
                );
            }
        };
        $app->instance(ResponseTransportManagerInterface::class, $failingTransport);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();
        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $configuration = RuntimeConfiguration::roadrunner(3, $requests);

        $exitCode = SequentialRequestLoop::runForSourceDriver(
            driver: 'roadrunner',
            plan: $plan,
            factory: $factory,
            configuration: $configuration,
            driverLabel: 'RoadRunner',
        );

        self::assertSame(1, $exitCode);
        self::assertSame(['/boom'], SequentialRequestLoopTestSuccessKernel::$handledPaths);
        self::assertCount(1, $failingTransport->records);
    }

    public function test_normalize_requests_uses_shared_normalizer_and_preserves_driver_label(): void
    {
        $this->expectException(RuntimeAdapterException::class);
        $this->expectExceptionMessage('FrankenPHP runtime request source must be iterable or callable.');

        SequentialRequestLoop::normalizeRequests(PHP_INT_MAX, 'FrankenPHP');
    }

    /**
     * @param string $path
     */
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

final class SequentialRequestLoopTestSuccessKernel implements KernelContract
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

final class SequentialRequestLoopTestThrowingKernel implements KernelContract
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
