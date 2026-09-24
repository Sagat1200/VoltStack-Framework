<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Runtime\DatabaseContext;
use Quantum\Database\Runtime\DatabaseExecutionScope;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\RuntimeContext;

final class DatabaseRuntimeScopeTest extends TestCase
{
    public function test_database_scope_is_available_during_the_request_and_finalized_on_scope_end(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('database.default', 'primary');

        $events = [];

        $app->onScopeEnd(function (Application $app, ?RuntimeContext $context) use (&$events): void {
            /** @var DatabaseExecutionScope $scope */
            $scope = $app->make(DatabaseExecutionScope::class);

            $events[] = [
                'finalized' => $scope->isFinalized(),
                'scope_id' => $scope->id(),
                'context_scope_id' => $context?->get('database.scope_id'),
            ];
        });

        $router = $app->make(Router::class);
        $router->get('/database-scope', DatabaseScopeController::class);

        $response = $app->make(HttpKernel::class)->handle(Request::create('/database-scope'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['same_scope_instance']);
        self::assertSame('primary', $payload['default_connection']);
        self::assertSame($payload['scope_id'], $payload['runtime_scope_id']);
        self::assertSame(
            [[
                'finalized' => true,
                'scope_id' => $payload['scope_id'],
                'context_scope_id' => $payload['scope_id'],
            ]],
            $events,
        );
        self::assertNull(RuntimeContext::current());
    }

    public function test_database_scope_cannot_be_resolved_outside_of_an_active_runtime_scope(): void
    {
        $this->expectException(RuntimeException::class);

        $app = new Application(sys_get_temp_dir());
        $app->make(DatabaseExecutionScope::class);
    }
}

final class DatabaseScopeController
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly DatabaseExecutionScope $scope,
        private readonly RuntimeContext $runtime,
    ) {
    }

    public function __invoke(DatabaseExecutionScope $scope): array
    {
        return [
            'scope_id' => $this->scope->id(),
            'runtime_scope_id' => $this->runtime->get('database.scope_id'),
            'default_connection' => $this->database->defaultConnectionName(),
            'same_scope_instance' => spl_object_id($this->scope) === spl_object_id($scope),
        ];
    }
}
