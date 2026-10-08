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
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
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

    public function test_runtime_manager_server_runs_frankenphp_adapter_sequentially(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRuntimeManagerKernel());

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
