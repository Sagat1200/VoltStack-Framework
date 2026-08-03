<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Controllers\Contracts\ControllerExecutionContextAwareInterface;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Http\JsonResponse;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Routing\Contracts\RouteBindableInterface;
use Quantum\Routing\Dispatching\ControllerDispatcher;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use Quantum\View\ViewFactory;
use RuntimeException;
use VoltStack\Framework\Application;

final class ControllerEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        TestContextAwareController::$injected = false;
        TestContextAwareController::$released = false;
        TestContextAwareController::$capturedPath = null;
        TestArrayCallableController::$invoked = false;

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

    public function test_it_dispatches_array_callable_controllers_through_the_engine(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/callable', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/callable', [TestArrayCallableController::class, 'show'])),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame('callable', $response->content());
        self::assertTrue(TestArrayCallableController::$invoked);
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

    public function test_it_applies_parameter_aliases_when_resolving_arguments(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/alias/42', 'GET');
        $route = new Route(RouteDefinition::make(['GET'], '/alias/{userId}', TestParameterAliasController::class . '@show'));
        $route->meta('parameter_aliases', [
            'user' => 'userId',
        ]);
        $match = new RouteMatch(
            $route,
            [
                'userId' => '42',
            ],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame('42', $response->content());
    }

    public function test_it_uses_the_missing_route_handler_when_a_binding_is_missing(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/binding/404', 'GET');
        $route = new Route(RouteDefinition::make(['GET'], '/binding/{user}', TestMissingBindingController::class . '@show'));
        $route->meta('missing', [
            'type' => 'status',
            'status' => 404,
        ]);
        $match = new RouteMatch(
            $route,
            [
                'user' => '404',
            ],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame(404, $response->statusCode());
    }

    public function test_it_normalizes_array_results_to_json_response(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/json', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/json', TestArrayResultController::class)),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('{"ok":true}', $response->content());
    }

    public function test_it_normalizes_string_results_to_response(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/string', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/string', TestStringResultController::class)),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('hello', $response->content());
    }

    public function test_it_normalizes_null_results_to_empty_response(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/null', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/null', TestNullResultController::class)),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('', $response->content());
    }

    public function test_it_passes_through_response_results_unchanged(): void
    {
        $app = new Application(sys_get_temp_dir());
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/raw', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/raw', TestResponseResultController::class)),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('raw', $response->content());
    }

    public function test_it_normalizes_view_results_to_response(): void
    {
        $basePath = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . uniqid('voltstack-view-result-', true);
        $viewsPath = $basePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
        $viewFile = $viewsPath . DIRECTORY_SEPARATOR . 'hello.php';

        if (! is_dir($viewsPath)) {
            mkdir($viewsPath, 0777, true);
        }

        file_put_contents($viewFile, '<?php echo "view:" . ($title ?? "");');

        $app = new Application($basePath);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/view', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/view', TestViewResultController::class)),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('view:hello', $response->content());
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

final class TestArrayCallableController
{
    public static bool $invoked = false;

    public function show(): string
    {
        self::$invoked = true;

        return 'callable';
    }
}

final class TestParameterAliasController
{
    public function show(string $user): string
    {
        return $user;
    }
}

final class TestMissingBindingController
{
    public function show(TestMissingBindingUser $user): string
    {
        return 'should-not-run';
    }
}

final class TestMissingBindingUser implements RouteBindableInterface
{
    public static function resolveRouteBinding(string $value, string $parameter, Request $request): mixed
    {
        return null;
    }
}

final class TestArrayResultController
{
    public function __invoke(): array
    {
        return [
            'ok' => true,
        ];
    }
}

final class TestStringResultController
{
    public function __invoke(): string
    {
        return 'hello';
    }
}

final class TestNullResultController
{
    public function __invoke(): mixed
    {
        return null;
    }
}

final class TestResponseResultController
{
    public function __invoke(): Response
    {
        return new Response('raw');
    }
}

final class TestViewResultController
{
    public function __construct(private readonly ViewFactory $views) {}

    public function __invoke(): mixed
    {
        return $this->views->make('hello', [
            'title' => 'hello',
        ]);
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