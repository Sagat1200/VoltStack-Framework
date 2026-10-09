<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Transport\Contracts\PreparedTransportResponseInterface;
use Quantum\Transport\Contracts\TransportEmitterInterface;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportResult;
use Quantum\Transport\Testing\InMemoryTransportEmitter;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Adapters\FrankenPhpRuntimeAdapter;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeManagerServer;
use VoltStack\Runtime\RuntimeConfiguration;

final class RuntimeManagerServerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        TestRuntimeManagerKernel::$handledPaths = [];
        TestFailingRuntimeManagerKernel::$calls = 0;
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-manager-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

return [
    'name' => 'VoltStack Runtime Manager',
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

    public function test_worker_factory_creates_ready_worker_session(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $session = $factory->create($plan, WorkerContext::create('frankenphp', 3));

        self::assertTrue($session->bootstrapResult()->ready());
        self::assertSame('READY', $session->bootstrapResult()->state()->value);
        self::assertSame('frankenphp', $session->context()->driver());
        self::assertSame(3, $session->context()->maxRequests());
        self::assertSame(0, $session->handledRequests());
        self::assertTrue($session->canAcceptMoreRequests());
    }

    public function test_worker_factory_uses_single_execution_mode_for_sapi_runtime_context(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('web')
            ->build();

        /** @var WorkerFactoryInterface $factory */
        $factory = $app->make(WorkerFactoryInterface::class);
        $session = $factory->create($plan, WorkerContext::create('sapi', 1));

        self::assertSame('single', $session->bootstrapResult()->context()?->executionMode());
        self::assertSame('web', $session->bootstrapResult()->context()?->profile());
    }

    public function test_runtime_manager_server_runs_frankenphp_adapter_sequentially(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());
        $emitter = new InMemoryTransportEmitter();
        $app->instance(TransportEmitterInterface::class, $emitter);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);
        $exitCode = $manager->run($plan, RuntimeConfiguration::frankenphp(
            maxRequests: 2,
            requestSource: [
                Request::create('/first'),
                Request::create('/second'),
                Request::create('/third'),
            ],
        ));

        self::assertSame(0, $exitCode);
        self::assertSame(['/first', '/second'], TestRuntimeManagerKernel::$handledPaths);
        self::assertTrue($manager->adapter('frankenphp')->capabilities()->persistent());
        self::assertFalse($manager->adapter('frankenphp')->capabilities()->concurrent());
        self::assertCount(2, $emitter->emitted());
        self::assertSame('runtime:/first', $emitter->emitted()[0]['response']->payload());
        self::assertSame('runtime:/second', $emitter->emitted()[1]['response']->payload());
    }

    public function test_runtime_manager_server_returns_non_zero_when_worker_must_terminate(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestFailingRuntimeManagerKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);
        $exitCode = $manager->run($plan, RuntimeConfiguration::frankenphp(
            maxRequests: 2,
            requestSource: [
                Request::create('/boom'),
                Request::create('/after'),
            ],
        ));

        self::assertSame(1, $exitCode);
        self::assertSame(1, TestFailingRuntimeManagerKernel::$calls);
    }

    public function test_runtime_manager_server_does_not_overconsume_incremental_request_sources_after_reaching_limit(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());
        $app->instance(TransportEmitterInterface::class, new InMemoryTransportEmitter());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);
        $exitCode = $manager->run($plan, RuntimeConfiguration::frankenphp(
            maxRequests: 2,
            requestSource: static function (): \Generator {
                yield Request::create('/first');
                yield Request::create('/second');
                throw new RuntimeException('request source consumed beyond admission limit');
            },
        ));

        self::assertSame(0, $exitCode);
        self::assertSame(['/first', '/second'], TestRuntimeManagerKernel::$handledPaths);
    }

    public function test_runtime_manager_server_requests_termination_when_transport_emission_fails(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());
        $app->instance(TransportEmitterInterface::class, new TestFailingTransportEmitter());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);
        $exitCode = $manager->run($plan, RuntimeConfiguration::frankenphp(
            maxRequests: 2,
            requestSource: [
                Request::create('/first'),
                Request::create('/second'),
            ],
        ));

        self::assertSame(1, $exitCode);
        self::assertSame(['/first'], TestRuntimeManagerKernel::$handledPaths);
    }

    public function test_runtime_manager_server_fails_when_no_request_source_and_native_runtime_is_unavailable(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);
        $manager->registerAdapter(new FrankenPhpRuntimeAdapter(
            nativeLoopInvoker: null,
            nativeAvailabilityResolver: static fn(): bool => false,
        ));

        $this->expectException(RuntimeAdapterException::class);
        $this->expectExceptionMessage('FrankenPHP native worker mode requires frankenphp_handle_request()');

        $manager->run($plan, RuntimeConfiguration::frankenphp(
            maxRequests: 2,
            requestSource: null,
        ));
    }

    public function test_runtime_manager_server_can_process_multiple_requests_through_native_frankenphp_loop(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());
        $emitter = new InMemoryTransportEmitter();
        $app->instance(TransportEmitterInterface::class, $emitter);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        $requests = [
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/native-first'],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/native-second'],
        ];

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);
        $manager->registerAdapter(new FrankenPhpRuntimeAdapter(
            nativeLoopInvoker: static function (callable $handler) use (&$requests): bool {
                $server = array_shift($requests);

                if ($server === null) {
                    return false;
                }

                $_GET = [];
                $_POST = [];
                $_COOKIE = [];
                $_FILES = [];
                $_SERVER = $server;

                $handler();

                return $requests !== [];
            },
            nativeAvailabilityResolver: static fn(): bool => true,
        ));

        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalCookie = $_COOKIE;
        $originalFiles = $_FILES;
        $originalServer = $_SERVER;

        try {
            $exitCode = $manager->run($plan, RuntimeConfiguration::frankenphp(
                maxRequests: 2,
                requestSource: null,
            ));
        } finally {
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_COOKIE = $originalCookie;
            $_FILES = $originalFiles;
            $_SERVER = $originalServer;
        }

        self::assertSame(0, $exitCode);
        self::assertSame(['/native-first', '/native-second'], TestRuntimeManagerKernel::$handledPaths);
        self::assertCount(2, $emitter->emitted());
        self::assertSame('runtime:/native-first', $emitter->emitted()[0]['response']->payload());
        self::assertSame('runtime:/native-second', $emitter->emitted()[1]['response']->payload());
    }

    public function test_runtime_manager_server_runs_sapi_adapter_with_single_request_capture(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());
        $emitter = new InMemoryTransportEmitter();
        $app->instance(TransportEmitterInterface::class, $emitter);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('web')
            ->build();

        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalCookie = $_COOKIE;
        $originalFiles = $_FILES;
        $originalServer = $_SERVER;

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/sapi',
        ];

        try {
            /** @var RuntimeManagerServer $manager */
            $manager = $app->make(RuntimeManagerServer::class);
            $exitCode = $manager->run($plan, new RuntimeConfiguration(
                driver: 'sapi',
                maxRequests: 1,
            ));
        } finally {
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_COOKIE = $originalCookie;
            $_FILES = $originalFiles;
            $_SERVER = $originalServer;
        }

        self::assertSame(0, $exitCode);
        self::assertSame(['/sapi'], TestRuntimeManagerKernel::$handledPaths);
        self::assertCount(1, $emitter->emitted());
        self::assertSame('runtime:/sapi', $emitter->emitted()[0]['response']->payload());
        self::assertSame('sapi', $manager->adapter('sapi')->id());
    }

    public function test_runtime_manager_server_runs_roadrunner_adapter_sequentially_with_request_source(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());
        $emitter = new InMemoryTransportEmitter();
        $app->instance(TransportEmitterInterface::class, $emitter);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);
        $exitCode = $manager->run($plan, RuntimeConfiguration::roadrunner(
            maxRequests: 2,
            requestSource: [
                Request::create('/rr-first'),
                Request::create('/rr-second'),
                Request::create('/rr-third'),
            ],
        ));

        self::assertSame(0, $exitCode);
        self::assertSame(['/rr-first', '/rr-second'], TestRuntimeManagerKernel::$handledPaths);
        self::assertTrue($manager->adapter('roadrunner')->capabilities()->persistent());
        self::assertFalse($manager->adapter('roadrunner')->capabilities()->concurrent());
        self::assertFalse($manager->adapter('roadrunner')->capabilities()->nativeHttp());
        self::assertCount(2, $emitter->emitted());
        self::assertSame('runtime:/rr-first', $emitter->emitted()[0]['response']->payload());
        self::assertSame('runtime:/rr-second', $emitter->emitted()[1]['response']->payload());
    }

    public function test_runtime_manager_server_fails_closed_for_roadrunner_without_request_source(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);

        $this->expectException(RuntimeAdapterException::class);
        $this->expectExceptionMessage('RoadRunner runtime adapter requires an in-memory request source');

        $manager->run($plan, RuntimeConfiguration::roadrunner(
            maxRequests: 2,
            requestSource: null,
        ));
    }

    public function test_runtime_manager_server_runs_openswoole_adapter_sequentially_with_request_source(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());
        $emitter = new InMemoryTransportEmitter();
        $app->instance(TransportEmitterInterface::class, $emitter);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);
        $exitCode = $manager->run($plan, RuntimeConfiguration::openswoole(
            maxRequests: 2,
            requestSource: [
                Request::create('/os-first'),
                Request::create('/os-second'),
                Request::create('/os-third'),
            ],
        ));

        self::assertSame(0, $exitCode);
        self::assertSame(['/os-first', '/os-second'], TestRuntimeManagerKernel::$handledPaths);
        self::assertTrue($manager->adapter('openswoole')->capabilities()->persistent());
        self::assertFalse($manager->adapter('openswoole')->capabilities()->concurrent());
        self::assertFalse($manager->adapter('openswoole')->capabilities()->nativeHttp());
        self::assertSame('simulated', $manager->adapter('openswoole')->capabilities()->evidenceLevel());
        self::assertCount(2, $emitter->emitted());
        self::assertSame('runtime:/os-first', $emitter->emitted()[0]['response']->payload());
        self::assertSame('runtime:/os-second', $emitter->emitted()[1]['response']->payload());
    }

    public function test_runtime_manager_server_fails_closed_for_openswoole_without_request_source(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);

        $this->expectException(RuntimeAdapterException::class);
        $this->expectExceptionMessage('OpenSwoole runtime adapter requires an in-memory request source');

        $manager->run($plan, RuntimeConfiguration::openswoole(
            maxRequests: 2,
            requestSource: null,
        ));
    }

    public function test_runtime_manager_server_rejects_invalid_sapi_max_requests(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('web')
            ->build();

        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);

        $this->expectException(RuntimeAdapterException::class);
        $this->expectExceptionMessage('SAPI runtime adapter only supports maxRequests=1.');

        $manager->run($plan, new RuntimeConfiguration(
            driver: 'sapi',
            maxRequests: 2,
        ));
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

final class TestRuntimeManagerKernel implements KernelContract
{
    /**
     * @var list<string>
     */
    public static array $handledPaths = [];

    public function handle(Request $request): Response
    {
        self::$handledPaths[] = $request->path();

        return new Response('runtime:' . $request->path());
    }

    public function setMiddlewares(array $middlewares): void
    {
    }

    public function pushMiddleware(callable|string|\Quantum\HttpKernel\Contracts\MiddlewareInterface $middleware): void
    {
    }
}

final class TestFailingRuntimeManagerKernel implements KernelContract
{
    public static int $calls = 0;

    public function handle(Request $request): Response
    {
        self::$calls++;

        throw new RuntimeException('runtime worker failure');
    }

    public function setMiddlewares(array $middlewares): void
    {
    }

    public function pushMiddleware(callable|string|\Quantum\HttpKernel\Contracts\MiddlewareInterface $middleware): void
    {
    }
}

final class TestFailingTransportEmitter implements TransportEmitterInterface
{
    public function emit(PreparedTransportResponseInterface $response, TransportContext $context): TransportResult
    {
        throw new RuntimeException('transport emitter failed');
    }
}
