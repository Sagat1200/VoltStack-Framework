<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorChainInterface;
use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorInterface;
use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorRegistryInterface;
use Quantum\Controllers\Interceptors\InterceptorDescriptor;
use Quantum\Controllers\Interceptors\InterceptorPhase;
use Quantum\Controllers\Interceptors\InterceptorScope;
use Quantum\Http\Request;
use Quantum\Routing\Dispatching\ControllerDispatcher;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use Throwable;
use VoltStack\Framework\Application;

final class ControllerInterceptorSystemTest extends TestCase
{
    protected function tearDown(): void
    {
        TestRecordingInterceptor::$events = [];
        TestRecordingController::$invoked = false;
        TestShortCircuitController::$invoked = false;
        TestConfiguredInterceptor::$capturedValue = null;
        TestConditionalController::$invoked = false;

        parent::tearDown();
    }

    public function test_it_executes_interceptors_in_priority_order_with_around_semantics(): void
    {
        $app = new Application(sys_get_temp_dir());

        $registry = $app->make(ControllerInterceptorRegistryInterface::class);
        $registry->register(new InterceptorDescriptor(
            id: 'a',
            interceptor: TestRecordingInterceptorA::class,
            scope: InterceptorScope::Execution,
            defaultPriority: 100,
            defaultPhase: InterceptorPhase::Around,
        ));
        $registry->register(new InterceptorDescriptor(
            id: 'b',
            interceptor: TestRecordingInterceptorB::class,
            scope: InterceptorScope::Execution,
            defaultPriority: 50,
            defaultPhase: InterceptorPhase::Around,
        ));
        $registry->register(new InterceptorDescriptor(
            id: 'c',
            interceptor: TestRecordingInterceptorC::class,
            scope: InterceptorScope::Execution,
            defaultPriority: 0,
            defaultPhase: InterceptorPhase::Around,
        ));

        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/order', 'GET');
        $route = new Route(RouteDefinition::make(['GET'], '/order', TestRecordingController::class));
        $route->meta('controller.interceptors', ['c', 'a', 'b']);
        $match = new RouteMatch($route, [], 'GET');

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame('ok', $response->content());
        self::assertSame([
            'A.before',
            'B.before',
            'C.before',
            'controller',
            'C.after',
            'B.after',
            'A.after',
        ], TestRecordingInterceptor::$events);
        self::assertTrue(TestRecordingController::$invoked);
    }

    public function test_it_supports_short_circuit_without_invoking_the_controller(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/short', 'GET');
        $route = new Route(RouteDefinition::make(['GET'], '/short', TestShortCircuitController::class));
        $route->meta('controller.interceptors', [
            TestShortCircuitInterceptor::class,
        ]);
        $match = new RouteMatch($route, [], 'GET');

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame('short', $response->content());
        self::assertFalse(TestShortCircuitController::$invoked);
    }

    public function test_it_allows_mutating_arguments_before_invocation(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/args/42', 'GET');
        $route = new Route(RouteDefinition::make(['GET'], '/args/{value}', TestArgumentController::class . '@show'));
        $route->meta('controller.interceptors', [
            TestArgumentMutatorInterceptor::class,
        ]);
        $match = new RouteMatch($route, ['value' => '42'], 'GET');

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame('mutated', $response->content());
    }

    public function test_it_allows_catching_exceptions_and_returning_a_recovery_result(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/boom', 'GET');
        $route = new Route(RouteDefinition::make(['GET'], '/boom', TestExceptionController::class));
        $route->meta('controller.interceptors', [
            TestExceptionCatchingInterceptor::class,
        ]);
        $match = new RouteMatch($route, [], 'GET');

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame('recovered', $response->content());
    }

    public function test_it_supports_interceptor_definitions_with_arguments(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/config', 'GET');
        $route = new Route(RouteDefinition::make(['GET'], '/config', TestConfiguredController::class));
        $route->meta('controller.interceptors', [
            [
                'interceptor' => TestConfiguredInterceptor::class,
                'arguments' => [
                    'value' => 'from-metadata',
                ],
            ],
        ]);
        $match = new RouteMatch($route, [], 'GET');

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame('from-metadata', $response->content());
        self::assertSame('from-metadata', TestConfiguredInterceptor::$capturedValue);
    }

    public function test_it_supports_interceptor_definitions_with_priority_override(): void
    {
        $app = new Application(sys_get_temp_dir());

        $registry = $app->make(ControllerInterceptorRegistryInterface::class);
        $registry->register(new InterceptorDescriptor(
            id: 'a',
            interceptor: TestRecordingInterceptorA::class,
            scope: InterceptorScope::Execution,
            defaultPriority: 0,
            defaultPhase: InterceptorPhase::Around,
        ));
        $registry->register(new InterceptorDescriptor(
            id: 'b',
            interceptor: TestRecordingInterceptorB::class,
            scope: InterceptorScope::Execution,
            defaultPriority: 0,
            defaultPhase: InterceptorPhase::Around,
        ));

        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/priority', 'GET');
        $route = new Route(RouteDefinition::make(['GET'], '/priority', TestRecordingController::class));
        $route->meta('controller.interceptors', [
            [
                'interceptor' => 'b',
                'priority' => 100,
            ],
            [
                'interceptor' => 'a',
                'priority' => 0,
            ],
        ]);
        $match = new RouteMatch($route, [], 'GET');

        $dispatcher->dispatch($match, $request);

        self::assertSame([
            'B.before',
            'A.before',
            'controller',
            'A.after',
            'B.after',
        ], TestRecordingInterceptor::$events);
    }

    public function test_it_supports_interceptor_definitions_with_conditions(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $route = new Route(RouteDefinition::make(['GET'], '/conditional', TestConditionalController::class));
        $route->meta('controller.interceptors', [
            [
                'interceptor' => TestShortCircuitInterceptor::class,
                'conditions' => [
                    [
                        'type' => 'http_method',
                        'value' => 'POST',
                    ],
                ],
            ],
        ]);
        $match = new RouteMatch($route, [], 'GET');

        $response = $dispatcher->dispatch($match, Request::create('/conditional', 'GET'));

        self::assertSame('conditional', $response->content());
        self::assertTrue(TestConditionalController::$invoked);
    }
}

abstract class TestRecordingInterceptor implements ControllerInterceptorInterface
{
    public static array $events = [];
}

final class TestRecordingInterceptorA extends TestRecordingInterceptor
{
    public function intercept($execution, ControllerInterceptorChainInterface $chain): mixed
    {
        self::$events[] = 'A.before';
        $result = $chain->proceed($execution);
        self::$events[] = 'A.after';

        return $result;
    }
}

final class TestRecordingInterceptorB extends TestRecordingInterceptor
{
    public function intercept($execution, ControllerInterceptorChainInterface $chain): mixed
    {
        self::$events[] = 'B.before';
        $result = $chain->proceed($execution);
        self::$events[] = 'B.after';

        return $result;
    }
}

final class TestRecordingInterceptorC extends TestRecordingInterceptor
{
    public function intercept($execution, ControllerInterceptorChainInterface $chain): mixed
    {
        self::$events[] = 'C.before';
        $result = $chain->proceed($execution);
        self::$events[] = 'C.after';

        return $result;
    }
}

final class TestRecordingController
{
    public static bool $invoked = false;

    public function __invoke(): string
    {
        self::$invoked = true;
        TestRecordingInterceptor::$events[] = 'controller';

        return 'ok';
    }
}

final class TestShortCircuitInterceptor implements ControllerInterceptorInterface
{
    public function intercept($execution, ControllerInterceptorChainInterface $chain): mixed
    {
        return 'short';
    }
}

final class TestShortCircuitController
{
    public static bool $invoked = false;

    public function __invoke(): string
    {
        self::$invoked = true;

        return 'should-not-run';
    }
}

final class TestArgumentMutatorInterceptor implements ControllerInterceptorInterface
{
    public function intercept($execution, ControllerInterceptorChainInterface $chain): mixed
    {
        $execution->setArguments(['mutated']);

        return $chain->proceed($execution);
    }
}

final class TestArgumentController
{
    public function show(string $value): string
    {
        return $value;
    }
}

final class TestExceptionCatchingInterceptor implements ControllerInterceptorInterface
{
    public function intercept($execution, ControllerInterceptorChainInterface $chain): mixed
    {
        try {
            return $chain->proceed($execution);
        } catch (Throwable $exception) {
            return 'recovered';
        }
    }
}

final class TestExceptionController
{
    public function __invoke(): string
    {
        throw new \RuntimeException('boom');
    }
}

final class TestConfiguredInterceptor implements ControllerInterceptorInterface
{
    public static ?string $capturedValue = null;

    public function __construct(private readonly string $value) {}

    public function intercept($execution, ControllerInterceptorChainInterface $chain): mixed
    {
        self::$capturedValue = $this->value;

        return $chain->proceed($execution);
    }
}

final class TestConfiguredController
{
    public function __invoke(): string
    {
        return TestConfiguredInterceptor::$capturedValue ?? 'missing';
    }
}

final class TestConditionalController
{
    public static bool $invoked = false;

    public function __invoke(): string
    {
        self::$invoked = true;

        return 'conditional';
    }
}
