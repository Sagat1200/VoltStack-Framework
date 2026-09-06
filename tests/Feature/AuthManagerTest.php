<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Exceptions\IdentityNotEligibleException;
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

    public function test_auth_manager_attempt_respects_configured_password_policy(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 23,
                'identifier' => 'policy-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.password.min_length', 20);

        $router = $app->make(Router::class);
        $router->get('/auth-attempt-policy', function (): array {
            return [
                'ok' => auth()->attempt([
                    'identifier' => 'policy-user@example.com',
                    'password' => 'secret-123',
                ]),
                'check' => auth()->check(),
            ];
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/auth-attempt-policy'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['ok']);
        self::assertFalse($payload['check']);
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

    public function test_attempt_or_fail_throws_when_identity_is_not_eligible(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 61,
                'identifier' => 'locked-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'security_state' => 'locked',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/auth-attempt-or-fail', function (): void {
            auth()->attemptOrFail([
                'identifier' => 'locked-user@example.com',
                'password' => 'secret-123',
            ]);
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/auth-attempt-or-fail',
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->statusCode());
        self::assertSame('Identity is not eligible for authentication.', $payload['message'] ?? null);
        self::assertSame('auth.identity_not_eligible', $response->headers()['X-Volt-Error-Code'] ?? null);
        self::assertSame('Session realm="VoltStack", Password realm="VoltStack"', $response->headers()['WWW-Authenticate'] ?? null);
    }

    public function test_auth_manager_can_use_file_session_driver(): void
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-auth-file-driver-' . bin2hex(random_bytes(4));
        @mkdir($basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        @mkdir($basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);

        try {
            $app = new Application($basePath);
            $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
                [
                    'id' => 71,
                    'identifier' => 'file-driver@example.com',
                    'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                    'type' => 'user',
                ],
            ]);
            $app->make(ConfigRepository::class)->set('auth.session.driver', 'file');

            $router = $app->make(Router::class);
            $router->get('/file-driver-login', function (): array {
                return [
                    'ok' => auth()->attempt([
                        'identifier' => 'file-driver@example.com',
                        'password' => 'secret-123',
                    ]),
                ];
            });

            $response = $app->make(HttpKernel::class)->handle(Request::create('/file-driver-login'));
            $sessionId = $response->headers()['X-Auth-Session'] ?? null;

            self::assertIsString($sessionId);
            self::assertFileExists($basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR . $sessionId . '.json');
        } finally {
            $sessionFiles = glob($basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR . '*.json');
            foreach ((array) $sessionFiles as $file) {
                @unlink((string) $file);
            }
            @rmdir($basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'sessions');
            @rmdir($basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'auth');
            @rmdir($basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework');
            @rmdir($basePath . DIRECTORY_SEPARATOR . 'storage');
            @rmdir($basePath . DIRECTORY_SEPARATOR . 'config');
            @rmdir($basePath);
        }
    }

    public function test_session_can_rotate_on_recover(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 81,
                'identifier' => 'rotate-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.rotate_on_recover', true);

        $router = $app->make(Router::class);
        $router->get('/rotate-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'rotate-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/rotate-me', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
                'session_id' => auth()->context()?->attribute('session_id'),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create('/rotate-login'));
        $originalSessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($originalSessionId);

        $meResponse = $kernel->handle(Request::create(
            '/rotate-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $originalSessionId],
        ));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($meResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $rotatedSessionId = $meResponse->headers()['X-Auth-Session'] ?? null;

        self::assertTrue($payload['check']);
        self::assertSame('81', (string) $payload['id']);
        self::assertIsString($rotatedSessionId);
        self::assertNotSame($originalSessionId, $rotatedSessionId);
        self::assertSame($rotatedSessionId, $payload['session_id']);

        $oldSessionResponse = $kernel->handle(Request::create(
            '/rotate-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $originalSessionId],
        ));

        /** @var array<string, mixed> $oldPayload */
        $oldPayload = json_decode($oldSessionResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($oldPayload['check']);
        self::assertNull($oldPayload['id']);
    }

    public function test_login_can_revoke_other_sessions_for_the_same_identity(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 91,
                'identifier' => 'revoke-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.revoke_others_on_login', true);

        $router = $app->make(Router::class);
        $router->get('/revoke-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'revoke-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/revoke-me', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create('/revoke-login'));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;
        $secondLogin = $kernel->handle(Request::create('/revoke-login'));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);
        self::assertIsString($secondSessionId);
        self::assertNotSame($firstSessionId, $secondSessionId);

        $oldSessionResponse = $kernel->handle(Request::create(
            '/revoke-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
        ));
        /** @var array<string, mixed> $oldPayload */
        $oldPayload = json_decode($oldSessionResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($oldPayload['check']);
        self::assertNull($oldPayload['id']);

        $currentSessionResponse = $kernel->handle(Request::create(
            '/revoke-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
        ));
        /** @var array<string, mixed> $currentPayload */
        $currentPayload = json_decode($currentSessionResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($currentPayload['check']);
        self::assertSame('91', (string) $currentPayload['id']);
    }

    public function test_auth_middleware_alias_requires_an_authenticated_session(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 101,
                'identifier' => 'middleware-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/middleware-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'middleware-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/middleware-protected', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        })->middleware('auth');

        $kernel = $app->make(HttpKernel::class);

        $guestResponse = $kernel->handle(Request::create(
            '/middleware-protected',
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $guestPayload = json_decode($guestResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $guestResponse->statusCode());
        self::assertSame('Authentication required.', $guestPayload['message'] ?? null);
        self::assertSame('auth.required', $guestResponse->headers()['X-Volt-Error-Code'] ?? null);

        $loginResponse = $kernel->handle(Request::create('/middleware-login'));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $protectedResponse = $kernel->handle(Request::create(
            '/middleware-protected',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
        ));
        $protectedPayload = json_decode($protectedResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $protectedResponse->statusCode());
        self::assertTrue($protectedPayload['check']);
        self::assertSame('101', (string) $protectedPayload['id']);
    }

    public function test_successful_login_can_persist_a_rehashed_password_hash(): void
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-auth-rehash-' . bin2hex(random_bytes(4));
        $storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'identities.json';
        $originalHash = password_hash('secret-123', PASSWORD_BCRYPT, ['cost' => 4]);

        @mkdir($basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        @mkdir(dirname($storagePath), 0777, true);
        file_put_contents($storagePath, json_encode([
            'identities' => [
                [
                    'id' => 111,
                    'identifier' => 'rehash-user@example.com',
                    'password_hash' => $originalHash,
                    'type' => 'user',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $app = new Application($basePath);
            $app->make(ConfigRepository::class)->set('auth.providers.local.storage_path', $storagePath);
            $app->make(ConfigRepository::class)->set('auth.password.rehash_options', ['cost' => 10]);

            $router = $app->make(Router::class);
            $router->get('/rehash-login', function (): array {
                return ['ok' => auth()->attempt([
                    'identifier' => 'rehash-user@example.com',
                    'password' => 'secret-123',
                ])];
            });

            $response = $app->make(HttpKernel::class)->handle(Request::create('/rehash-login'));
            $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
            $stored = json_decode((string) file_get_contents($storagePath), true, 512, JSON_THROW_ON_ERROR);
            $storedHash = $stored['identities'][0]['password_hash'] ?? null;

            self::assertTrue($payload['ok']);
            self::assertIsString($storedHash);
            self::assertTrue(password_verify('secret-123', $storedHash));
            self::assertNotSame($originalHash, $storedHash);
            self::assertFalse(password_needs_rehash($storedHash, PASSWORD_DEFAULT, ['cost' => 10]));
        } finally {
            @unlink($storagePath);
            @rmdir($basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'auth');
            @rmdir($basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework');
            @rmdir($basePath . DIRECTORY_SEPARATOR . 'storage');
            @rmdir($basePath . DIRECTORY_SEPARATOR . 'config');
            @rmdir($basePath);
        }
    }

    public function test_guest_middleware_alias_rejects_authenticated_users(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 121,
                'identifier' => 'guest-middleware-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/guest-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'guest-middleware-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/guest-only', function (): array {
            return [
                'guest' => auth()->guest(),
                'check' => auth()->check(),
            ];
        })->middleware('guest');

        $kernel = $app->make(HttpKernel::class);

        $guestResponse = $kernel->handle(Request::create('/guest-only'));
        $guestPayload = json_decode($guestResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $guestResponse->statusCode());
        self::assertTrue($guestPayload['guest']);
        self::assertFalse($guestPayload['check']);

        $loginResponse = $kernel->handle(Request::create('/guest-login'));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $authenticatedResponse = $kernel->handle(Request::create(
            '/guest-only',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $authenticatedPayload = json_decode($authenticatedResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $authenticatedResponse->statusCode());
        self::assertSame('Only guests may access this resource.', $authenticatedPayload['message'] ?? null);
        self::assertSame('auth.guest_only', $authenticatedPayload['reason_code'] ?? null);
        self::assertSame('auth.guest_only', $authenticatedResponse->headers()['X-Volt-Error-Code'] ?? null);
        self::assertArrayNotHasKey('WWW-Authenticate', $authenticatedResponse->headers());
    }
}