<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\RuntimeContext;

final class RuntimeScopeTest extends TestCase
{
    public function test_runtime_context_is_available_during_the_request_and_cleared_afterwards(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->scoped(TestScopedService::class, fn (): TestScopedService => new TestScopedService(bin2hex(random_bytes(4))));

        $router = $app->make(Router::class);
        $router->get('/scope', ScopedRequestController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/scope'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('/scope', $payload['path']);
        self::assertSame(32, strlen($payload['request_id']));
        self::assertTrue($payload['same_service_instance']);
        self::assertNull(RuntimeContext::current());
    }

    public function test_scoped_services_are_recreated_for_each_request(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->scoped(TestScopedService::class, fn (): TestScopedService => new TestScopedService(bin2hex(random_bytes(4))));

        $router = $app->make(Router::class);
        $router->get('/scoped', ScopedServiceController::class);
        $kernel = $app->make(HttpKernel::class);

        $firstResponse = $kernel->handle(Request::create('/scoped'));
        $secondResponse = $kernel->handle(Request::create('/scoped'));

        /** @var array<string, mixed> $first */
        $first = json_decode($firstResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $second */
        $second = json_decode($secondResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($first['same_request_instance']);
        self::assertTrue($second['same_request_instance']);
        self::assertNotSame($first['service_id'], $second['service_id']);
    }

    public function test_request_cannot_be_resolved_outside_of_an_active_scope(): void
    {
        $this->expectException(RuntimeException::class);

        $app = new Application(sys_get_temp_dir());
        $app->make(Request::class);
    }

    public function test_scope_lifecycle_callbacks_run_around_the_request(): void
    {
        $app = new Application(sys_get_temp_dir());
        $events = [];

        $app->onScopeStart(function (Application $app, RuntimeContext $context) use (&$events): void {
            $events[] = [
                'phase' => 'start',
                'path' => $context->request()->path(),
                'current_matches' => RuntimeContext::current()?->requestId() === $context->requestId(),
            ];

            $context->set('hook.started', true);
        });

        $app->onScopeEnd(function (Application $app, ?RuntimeContext $context) use (&$events): void {
            $events[] = [
                'phase' => 'end',
                'path' => $context?->request()->path(),
                'current_matches' => RuntimeContext::current()?->requestId() === $context?->requestId(),
            ];
        });

        $router = $app->make(Router::class);
        $router->get('/hooks', HookedScopeController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/hooks'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['hook_started']);
        self::assertSame(
            [
                ['phase' => 'start', 'path' => '/hooks', 'current_matches' => true],
                ['phase' => 'end', 'path' => '/hooks', 'current_matches' => true],
            ],
            $events,
        );
        self::assertNull(RuntimeContext::current());
    }
}

final class ScopedRequestController
{
    public function __construct(
        private readonly Request $request,
        private readonly RuntimeContext $context,
        private readonly TestScopedService $service,
    ) {
    }

    public function __invoke(TestScopedService $service): array
    {
        return [
            'path' => $this->request->path(),
            'request_id' => $this->context->requestId(),
            'same_service_instance' => spl_object_id($this->service) === spl_object_id($service),
        ];
    }
}

final class ScopedServiceController
{
    public function __construct(private readonly TestScopedService $service)
    {
    }

    public function __invoke(TestScopedService $service): array
    {
        return [
            'service_id' => $this->service->id,
            'same_request_instance' => spl_object_id($this->service) === spl_object_id($service),
        ];
    }
}

final class TestScopedService
{
    public function __construct(public readonly string $id)
    {
    }
}

final class HookedScopeController
{
    public function __construct(private readonly RuntimeContext $context)
    {
    }

    public function __invoke(): array
    {
        return [
            'hook_started' => $this->context->get('hook.started', false),
        ];
    }
}
