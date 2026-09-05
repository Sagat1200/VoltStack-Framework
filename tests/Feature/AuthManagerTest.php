<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Config\ConfigRepository;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

final class AuthManagerTest extends TestCase
{
    public function test_auth_manager_stores_user_inside_the_active_request_scope(): void
    {
        $app = new Application(sys_get_temp_dir());
        $router = $app->make(Router::class);
        $router->get('/auth', function (): array {
            auth()->setUser([
                'id' => 7,
                'name' => 'VoltStack User',
            ]);

            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/auth'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['check']);
        self::assertSame(7, $payload['id']);
    }

    public function test_auth_manager_exposes_authentication_context_and_contract_binding(): void
    {
        $app = new Application(sys_get_temp_dir());

        self::assertInstanceOf(AuthenticationManagerInterface::class, $app->make(AuthenticationManagerInterface::class));

        $router = $app->make(Router::class);
        $router->get('/auth-context', function (): array {
            auth()->setUser([
                'id' => 11,
                'name' => 'VoltStack Context User',
            ]);

            $context = auth()->context();

            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
                'context_request_id' => $context?->requestId,
                'context_identity_id' => $context?->reference->identifier->value,
                'context_identity_type' => $context?->reference->type,
                'context_method' => $context?->method,
            ];
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/auth-context'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['check']);
        self::assertSame(11, $payload['id']);
        self::assertSame('11', $payload['context_identity_id']);
        self::assertSame('array', $payload['context_identity_type']);
        self::assertSame('manual', $payload['context_method']);
        self::assertNotSame('', (string) ($payload['context_request_id'] ?? ''));
    }

    public function test_auth_manager_attempt_authenticates_with_local_provider_credentials(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 21,
                'identifier' => 'alice@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
                'name' => 'Alice',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/auth-attempt-ok', function (): array {
            $ok = auth()->attempt([
                'identifier' => 'alice@example.com',
                'password' => 'secret-123',
            ]);

            return [
                'ok' => $ok,
                'check' => auth()->check(),
                'id' => auth()->id(),
                'method' => auth()->context()?->method,
                'type' => auth()->context()?->reference->type,
            ];
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/auth-attempt-ok'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertTrue($payload['check']);
        self::assertSame('21', (string) $payload['id']);
        self::assertSame('password', $payload['method']);
        self::assertSame('user', $payload['type']);
    }

    public function test_auth_manager_attempt_rejects_invalid_password_without_authenticating(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 22,
                'identifier' => 'bob@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
                'name' => 'Bob',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/auth-attempt-fail', function (): array {
            $ok = auth()->attempt([
                'identifier' => 'bob@example.com',
                'password' => 'wrong-secret',
            ]);

            return [
                'ok' => $ok,
                'check' => auth()->check(),
                'id' => auth()->id(),
                'context' => auth()->context() !== null,
            ];
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/auth-attempt-fail'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['ok']);
        self::assertFalse($payload['check']);
        self::assertNull($payload['id']);
        self::assertFalse($payload['context']);
    }
}