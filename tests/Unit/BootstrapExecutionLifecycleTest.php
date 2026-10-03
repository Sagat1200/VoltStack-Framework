<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\Config\BootstrapConfiguration;
use Quantum\Bootstrap\Context\BootstrapContext;
use Quantum\Bootstrap\Contracts\BootstrapWarmerInterface;
use Quantum\Bootstrap\Contracts\BootstrapperInterface;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\Engine\InMemoryTelemetryExporter;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Context\WorkerLifecycle;
use VoltStack\Runtime\RequestRunner;
use VoltStack\Runtime\Reset\ResetManager;
use VoltStack\Runtime\Reset\ResettableInterface;

final class BootstrapExecutionLifecycleTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        TestBootstrapWarmer::$calls = 0;
        TestRequestResetter::$calls = 0;
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-bootstrap-execution-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

return [
    'name' => 'VoltStack Bootstrap Execution',
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

    public function test_bootstrapper_returns_ready_result_and_executes_warmers(): void
    {
        $artifactDirectory = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'bootstrap';

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('worker')
            ->withArtifactDirectory($artifactDirectory)
            ->withBootstrapConfiguration(BootstrapConfiguration::fromSections(
                operational: [
                    'warmers' => [TestBootstrapWarmer::class],
                    'emit_phase_telemetry' => true,
                ],
            ))
            ->build();

        $app = new Application($this->basePath);
        /** @var BootstrapperInterface $bootstrapper */
        $bootstrapper = $app->make(BootstrapperInterface::class);

        $result = $bootstrapper->bootPlan($plan, BootstrapContext::forConsole(
            environment: 'testing',
            executionMode: 'single',
            profile: 'worker',
        ));

        self::assertTrue($result->ready());
        self::assertTrue($result->warmed());
        self::assertSame('READY', $result->state()->value);
        self::assertSame([TestBootstrapWarmer::class], $result->warmedBy());
        self::assertNotNull($result->generationId());
        self::assertNotNull($result->manifestPath());
        self::assertFileExists((string) $result->manifestPath());
        self::assertSame(1, TestBootstrapWarmer::$calls);
        self::assertTrue($app->isBooted());
        self::assertSame('warmed', $app->make('bootstrap.warmer.status'));
        self::assertCount(7, $result->phaseProfiles());

        $phaseNames = array_map(
            static fn(\Quantum\Bootstrap\Telemetry\BootstrapPhaseProfile $profile): string => $profile->phase()->value,
            $result->phaseProfiles(),
        );

        self::assertSame(
            ['DISCOVERING', 'REGISTERING', 'CONFIGURING', 'COMPILING', 'BOOTING', 'WARMING', 'READY'],
            $phaseNames,
        );

        $exporter = $app->make(TelemetryExporterInterface::class);
        self::assertInstanceOf(InMemoryTelemetryExporter::class, $exporter);

        $signals = $exporter->signals();
        self::assertCount(8, $signals);
        self::assertSame('bootstrap_phase', $signals[0]->name);
        self::assertSame('bootstrap_profile', $signals[7]->name);
        self::assertSame($signals[0]->traceId, $signals[7]->traceId);
    }

    public function test_request_runner_resets_worker_and_clears_reset_request(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestRequestRunnerKernel($app));
        $app->make(ResetManager::class)->register(new TestRequestResetter());

        /** @var RequestRunner $runner */
        $runner = $app->make(RequestRunner::class);
        $result = $runner->run(Request::create('/reset'));

        self::assertTrue($result->successful());
        self::assertSame(WorkerDisposition::Reset, $result->workerDisposition());
        self::assertSame('ok', $result->response()?->content());
        self::assertTrue($result->resetReport()->successful());
        self::assertSame(1, $result->resetReport()->executedCount());
        self::assertSame(1, TestRequestResetter::$calls);
        self::assertFalse($app->make(WorkerLifecycle::class)->shouldReset());
        self::assertFalse($app->resolved('request.runner.scope'));
    }

    public function test_request_runner_requests_termination_when_kernel_fails(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new TestFailingRequestRunnerKernel());
        $app->make(ResetManager::class)->register(new TestRequestResetter());

        /** @var RequestRunner $runner */
        $runner = $app->make(RequestRunner::class);
        $result = $runner->run(Request::create('/boom'));

        self::assertFalse($result->successful());
        self::assertSame(WorkerDisposition::Terminate, $result->workerDisposition());
        self::assertNull($result->response());
        self::assertInstanceOf(RuntimeException::class, $result->exception());
        self::assertTrue($app->make(WorkerLifecycle::class)->shouldTerminate());
        self::assertTrue($result->resetReport()->successful());
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

final class TestBootstrapWarmer implements BootstrapWarmerInterface
{
    public static int $calls = 0;

    public function warm(Application $app, \Quantum\Bootstrap\ApplicationPlan $plan, ?\Quantum\Bootstrap\Context\BootstrapContext $context = null): void
    {
        self::$calls++;
        $app->instance('bootstrap.warmer.status', 'warmed');
    }
}

final class TestRequestResetter implements ResettableInterface
{
    public static int $calls = 0;

    public function reset(Application $app): void
    {
        self::$calls++;
        $app->instance('request.runner.reset.status', 'done');
    }
}

final class TestRequestRunnerKernel implements KernelContract
{
    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request): Response
    {
        $this->app->scopedInstance('request.runner.scope', 'dirty');
        $this->app->make(WorkerLifecycle::class)->request(WorkerDisposition::Reset);

        return new Response('ok');
    }

    public function setMiddlewares(array $middlewares): void
    {
    }

    public function pushMiddleware(callable|string|\Quantum\HttpKernel\Contracts\MiddlewareInterface $middleware): void
    {
    }
}

final class TestFailingRequestRunnerKernel implements KernelContract
{
    public function handle(Request $request): Response
    {
        throw new RuntimeException('runner failed');
    }

    public function setMiddlewares(array $middlewares): void
    {
    }

    public function pushMiddleware(callable|string|\Quantum\HttpKernel\Contracts\MiddlewareInterface $middleware): void
    {
    }
}
