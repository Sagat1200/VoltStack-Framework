<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Controllers\Contracts\ControllerExecutionContextAwareInterface;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Routing\Dispatching\ControllerDispatcher;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use RuntimeException;
use VoltStack\Framework\Application;

final class ControllerEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        TestContextAwareController::$injected = false;
        TestContextAwareController::$released = false;
        TestContextAwareController::$capturedPath = null;

        parent::tearDown();
    }

    public function test_it_dispatches_invokable_controllers_through_the_engine(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/invokable', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/invokable', TestInvokableController::class)),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('invokable', $response->content());
    }

    public function test_it_dispatches_class_at_method_controllers_through_the_engine(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/method', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/method', TestMethodController::class . '@show')),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame('/method', $response->content());
    }

    public function test_it_injects_and_releases_execution_context_on_success(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/context', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/context', TestContextAwareController::class)),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame('/context', $response->content());
        self::assertTrue(TestContextAwareController::$injected);
        self::assertTrue(TestContextAwareController::$released);
        self::assertSame('/context', TestContextAwareController::$capturedPath);
    }

    public function test_it_releases_execution_context_on_exception(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/exception', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/exception', TestContextAwareExceptionController::class)),
            [],
            'GET',
        );

        try {
            $dispatcher->dispatch($match, $request);
            self::fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertTrue(TestContextAwareExceptionController::$released);
    }
}

final class TestInvokableController
{
    public function __invoke(): string
    {
        return 'invokable';
    }
}

final class TestMethodController
{
    public function show(Request $request): string
    {
        return $request->path();
    }
}

final class TestContextAwareController implements ControllerExecutionContextAwareInterface
{
    public static bool $injected = false;
    public static bool $released = false;
    public static ?string $capturedPath = null;

    private ?ControllerExecutionContext $context = null;

    public function setControllerExecutionContext(ControllerExecutionContext $context): void
    {
        self::$injected = true;
        $this->context = $context;
    }

    public function releaseControllerExecutionContext(): void
    {
        self::$released = true;
        $this->context = null;
    }

    public function __invoke(): string
    {
        $path = $this->context?->request()->path();
        self::$capturedPath = $path;

        return (string) $path;
    }
}

final class TestContextAwareExceptionController implements ControllerExecutionContextAwareInterface
{
    public static bool $released = false;

    public function setControllerExecutionContext(ControllerExecutionContext $context): void {}

    public function releaseControllerExecutionContext(): void
    {
        self::$released = true;
    }

    public function __invoke(): string
    {
        throw new RuntimeException('boom');
    }
}

