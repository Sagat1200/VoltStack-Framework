<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Support\AuthenticationHttpState;
use Quantum\Config\ConfigRepository;
use Quantum\Facades\Auth;
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

    public function test_auth_manager_restores_authenticated_session_across_requests_and_logout_clears_it(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 31,
                'identifier' => 'session-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
                'name' => 'Session User',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/session-login', function (): array {
            return [
                'ok' => auth()->attempt([
                    'identifier' => 'session-user@example.com',
                    'password' => 'secret-123',
                ]),
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        });

        $router->get('/session-me', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
                'method' => auth()->context()?->method,
                'session_id' => auth()->context()?->attribute('session_id'),
            ];
        });

        $router->post('/session-logout', function (): array {
            auth()->logout();

            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        });

        $kernel = $app->make(HttpKernel::class);

        $loginResponse = $kernel->handle(Request::create('/session-login'));
        /** @var array<string, mixed> $loginPayload */
        $loginPayload = json_decode($loginResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertTrue($loginPayload['ok']);
        self::assertTrue($loginPayload['check']);
        self::assertSame('31', (string) $loginPayload['id']);
        self::assertIsString($sessionId);
        self::assertNotSame('', trim((string) $sessionId));
        self::assertStringContainsString(AuthenticationHttpState::SESSION_COOKIE_NAME . '=', $loginResponse->headers()['Set-Cookie'] ?? '');

        $meResponse = $kernel->handle(Request::create(
            '/session-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
        ));

        /** @var array<string, mixed> $mePayload */
        $mePayload = json_decode($meResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($mePayload['check']);
        self::assertSame('31', (string) $mePayload['id']);
        self::assertSame('password', $mePayload['method']);
        self::assertSame($sessionId, $mePayload['session_id']);

        $logoutResponse = $kernel->handle(Request::create(
            '/session-logout',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
        ));

        /** @var array<string, mixed> $logoutPayload */
        $logoutPayload = json_decode($logoutResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($logoutPayload['check']);
        self::assertNull($logoutPayload['id']);
        self::assertStringContainsString('Max-Age=0', $logoutResponse->headers()['Set-Cookie'] ?? '');

        $afterLogoutResponse = $kernel->handle(Request::create(
            '/session-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
        ));

        /** @var array<string, mixed> $afterLogoutPayload */
        $afterLogoutPayload = json_decode($afterLogoutResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($afterLogoutPayload['check']);
        self::assertNull($afterLogoutPayload['id']);
        self::assertNull($afterLogoutPayload['method']);
        self::assertNull($afterLogoutPayload['session_id']);
    }

    public function test_auth_facade_authenticates_and_uses_configured_cookie_name(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 41,
                'identifier' => 'facade-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
                'name' => 'Facade User',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.cookie', 'voltstack_auth_custom');
        $app->make(ConfigRepository::class)->set('auth.session.lifetime', 120);

        $router = $app->make(Router::class);
        $router->get('/auth-facade', function (): array {
            return [
                'ok' => Auth::attempt([
                    'identifier' => 'facade-user@example.com',
                    'password' => 'secret-123',
                ]),
                'check' => Auth::check(),
                'id' => Auth::id(),
            ];
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/auth-facade'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertTrue($payload['check']);
        self::assertSame('41', (string) $payload['id']);
        self::assertStringContainsString('voltstack_auth_custom=', $response->headers()['Set-Cookie'] ?? '');
        self::assertStringContainsString('Max-Age=120', $response->headers()['Set-Cookie'] ?? '');
    }

    public function test_expired_session_is_rejected_and_cookie_is_cleared(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 51,
                'identifier' => 'expired-session@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
                'name' => 'Expired Session User',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.lifetime', 0);

        $router = $app->make(Router::class);
        $router->get('/expired-login', function (): array {
            return [
                'ok' => auth()->attempt([
                    'identifier' => 'expired-session@example.com',
                    'password' => 'secret-123',
                ]),
            ];
        });

        $router->get('/expired-me', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create('/expired-login'));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);
        self::assertNotSame('', trim((string) $sessionId));

        $recoveryResponse = $kernel->handle(Request::create(
            '/expired-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
        ));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($recoveryResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['check']);
        self::assertNull($payload['id']);
        self::assertStringContainsString('Max-Age=0', $recoveryResponse->headers()['Set-Cookie'] ?? '');
    }
}
