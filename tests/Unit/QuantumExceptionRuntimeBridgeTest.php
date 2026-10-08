<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Bridges\Runtime\ExceptionRuntimeBridge;
use Quantum\Exceptions\Bridges\Runtime\FinalizationOutcome;
use Quantum\Exceptions\Bridges\Runtime\RuntimeOperation;
use Quantum\Http\Request;
use Quantum\Http\Response;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Context\RuntimeContext;
use VoltStack\Runtime\Context\ScopeManager;
use VoltStack\Runtime\Context\WorkerLifecycle;
use VoltStack\Runtime\RequestRunner;

final class QuantumExceptionRuntimeBridgeTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-exception-runtime-bridge-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_runtime_bridge_creates_context_and_close_is_idempotent(): void
    {
        $app = new Application($this->basePath);
        $scopeManager = $app->make(ScopeManager::class);
        $bridge = $app->make(ExceptionRuntimeBridge::class);

        $runtimeContext = $scopeManager->begin(Request::create('/runtime'));
        $scope = $bridge->begin(new RuntimeOperation(
            transport: 'http',
            operationId: $runtimeContext->requestId(),
            metadata: ['path' => '/runtime'],
        ));
        $context = $bridge->context($scope);

        self::assertSame($scope->id(), $runtimeContext->get('exceptions.scope_id'));
        self::assertSame($runtimeContext->requestId(), $context->correlationId);
        self::assertSame('http', $context->attributes['transport_kind'] ?? null);

        $bridge->finalize($scope, FinalizationOutcome::completed(['prepared' => true]));

        self::assertTrue($scope->isFinalized());
        self::assertSame('completed', $runtimeContext->get('exceptions.finalization_outcome'));

        $scopeManager->end();

        $first = $bridge->close($scope);
        $second = $bridge->close($scope);

        self::assertTrue($first->successful());
        self::assertSame($first, $second);
        self::assertNull(RuntimeContext::current());
    }

    public function test_request_runner_records_completed_exception_runtime_metadata(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new RuntimeBridgeSuccessKernel());

        $events = [];
        $app->onScopeEnd(function (Application $app, ?RuntimeContext $context) use (&$events): void {
            $events[] = [
                'scope_id' => $context?->get('exceptions.scope_id'),
                'outcome' => $context?->get('exceptions.finalization_outcome'),
                'finalized' => $context?->get('exceptions.scope_finalized'),
            ];
        });

        $runner = $app->make(RequestRunner::class);
        $result = $runner->run(Request::create('/ok'));

        self::assertTrue($result->successful());
        self::assertSame('ok', $result->response()?->content());
        self::assertSame(
            [[
                'scope_id' => $events[0]['scope_id'],
                'outcome' => 'completed',
                'finalized' => true,
            ]],
            $events,
        );
        self::assertNotEmpty($events[0]['scope_id']);
        self::assertNull(RuntimeContext::current());
    }

    public function test_request_runner_marks_failed_outcome_and_requests_termination(): void
    {
        $app = new Application($this->basePath);
        $app->instance(KernelContract::class, new RuntimeBridgeFailingKernel());

        $events = [];
        $app->onScopeEnd(function (Application $app, ?RuntimeContext $context) use (&$events): void {
            $events[] = [
                'outcome' => $context?->get('exceptions.finalization_outcome'),
                'exception_scope_id' => $context?->get('exceptions.scope_id'),
            ];
        });

        $runner = $app->make(RequestRunner::class);
        $result = $runner->run(Request::create('/boom'));

        self::assertFalse($result->successful());
        self::assertNull($result->response());
        self::assertInstanceOf(RuntimeException::class, $result->exception());
        self::assertTrue($app->make(WorkerLifecycle::class)->shouldTerminate());
        self::assertSame('failed', $events[0]['outcome']);
        self::assertNotEmpty($events[0]['exception_scope_id']);
        self::assertNull(RuntimeContext::current());
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
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}

final class RuntimeBridgeSuccessKernel implements KernelContract
{
    public function handle(Request $request): Response
    {
        return new Response('ok');
    }

    public function setMiddlewares(array $middlewares): void
    {
    }

    public function pushMiddleware(callable|string|\Quantum\HttpKernel\Contracts\MiddlewareInterface $middleware): void
    {
    }
}

final class RuntimeBridgeFailingKernel implements KernelContract
{
    public function handle(Request $request): Response
    {
        throw new RuntimeException('runtime bridge failure');
    }

    public function setMiddlewares(array $middlewares): void
    {
    }

    public function pushMiddleware(callable|string|\Quantum\HttpKernel\Contracts\MiddlewareInterface $middleware): void
    {
    }
}
