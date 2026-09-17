<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Exceptions\IdentityNotEligibleException;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Support\AuthenticationHttpState;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Facades\Auth;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\RuntimeContext;

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
                'context_strength' => $context?->authenticationStrength()->name,
                'context_assurance_profile' => $context?->authenticationAssuranceProfile(),
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
        self::assertSame('Password', $payload['context_strength']);
        self::assertSame('single_factor', $payload['context_assurance_profile']);
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
                'strength' => auth()->context()?->authenticationStrength()->name,
                'assurance_profile' => auth()->context()?->authenticationAssuranceProfile(),
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
        self::assertSame('Password', $payload['strength']);
        self::assertSame('single_factor', $payload['assurance_profile']);
    }

    public function test_auth_manager_attempt_can_establish_multi_factor_assurance_with_second_factor(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 24,
                'identifier' => 'mfa-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/auth-attempt-mfa', function (): array {
            $ok = auth()->attempt([
                'identifier' => 'mfa-user@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ]);

            return [
                'ok' => $ok,
                'check' => auth()->check(),
                'id' => auth()->id(),
                'strength' => auth()->context()?->authenticationStrength()->name,
                'assurance_profile' => auth()->context()?->authenticationAssuranceProfile(),
                'amr' => auth()->context()?->attribute('amr'),
            ];
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/auth-attempt-mfa'));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertTrue($payload['check']);
        self::assertSame('24', (string) $payload['id']);
        self::assertSame('MultiFactor', $payload['strength']);
        self::assertSame('multi_factor', $payload['assurance_profile']);
        self::assertSame(['pwd', 'mfa'], $payload['amr']);
    }

    public function test_step_up_can_elevate_an_existing_authenticated_session_without_relogin(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 25,
                'identifier' => 'step-up-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/step-up-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'step-up-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->post('/step-up-elevate', function (): array {
            $before = auth()->context();
            $ok = auth()->stepUp([
                'second_factor' => '654321',
            ]);
            $after = auth()->context();

            return [
                'ok' => $ok,
                'before_strength' => $before?->authenticationStrength()->name,
                'after_strength' => $after?->authenticationStrength()->name,
                'after_profile' => $after?->authenticationAssuranceProfile(),
                'amr' => $after?->attribute('amr'),
                'before_session_id' => $before?->attribute('session_id'),
                'after_session_id' => $after?->attribute('session_id'),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create('/step-up-login'));
        $originalSessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($originalSessionId);

        $stepUpResponse = $kernel->handle(Request::create(
            '/step-up-elevate',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $originalSessionId],
        ));
        $payload = json_decode($stepUpResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $elevatedSessionId = $stepUpResponse->headers()['X-Auth-Session'] ?? null;

        self::assertTrue($payload['ok']);
        self::assertSame('Password', $payload['before_strength']);
        self::assertSame('MultiFactor', $payload['after_strength']);
        self::assertSame('multi_factor', $payload['after_profile']);
        self::assertSame(['pwd', 'mfa'], $payload['amr']);
        self::assertIsString($elevatedSessionId);
        self::assertNotSame($originalSessionId, $elevatedSessionId);
        self::assertSame($originalSessionId, $payload['before_session_id']);
        self::assertSame($elevatedSessionId, $payload['after_session_id']);
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
                'strength' => auth()->context()?->authenticationStrength()->name,
                'assurance_profile' => auth()->context()?->authenticationAssuranceProfile(),
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
        self::assertSame('Password', $mePayload['strength']);
        self::assertSame('single_factor', $mePayload['assurance_profile']);

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

    public function test_auth_manager_can_list_sessions_with_safe_public_identifiers_and_revoke_by_public_id(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 32,
                'identifier' => 'session-inventory@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
                'name' => 'Session Inventory User',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/inventory-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'session-inventory@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/inventory-sessions', function (): array {
            $current = auth()->currentSession();

            return [
                'current_public_id' => $current?->publicId,
                'sessions' => array_map(static fn ($session): array => [
                    'public_id' => $session->publicId,
                    'current' => $session->current,
                    'method' => $session->method,
                ], auth()->sessions()),
            ];
        });
        $router->post('/inventory-revoke', function (): array {
            $current = auth()->currentSession();
            $sessions = auth()->sessions();
            $target = null;

            foreach ($sessions as $session) {
                if (! $session->current) {
                    $target = $session;
                    break;
                }
            }

            return [
                'current_public_id' => $current?->publicId,
                'target_public_id' => $target?->publicId,
                'revoked' => $target !== null ? auth()->revokeSession($target->publicId) : false,
            ];
        });
        $router->get('/inventory-me', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        })->middleware('auth');

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create('/inventory-login'));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;
        $secondLogin = $kernel->handle(Request::create('/inventory-login'));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);
        self::assertIsString($secondSessionId);
        self::assertNotSame($firstSessionId, $secondSessionId);

        $inventoryResponse = $kernel->handle(Request::create(
            '/inventory-sessions',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $inventoryPayload['sessions'] ?? [];

        self::assertCount(2, $sessions);
        self::assertIsString($inventoryPayload['current_public_id'] ?? null);
        self::assertStringStartsWith('sess_pub_', $inventoryPayload['current_public_id']);
        self::assertNotSame($secondSessionId, $inventoryPayload['current_public_id']);
        self::assertSame(1, count(array_filter($sessions, static fn (array $session): bool => ($session['current'] ?? false) === true)));
        self::assertFalse(in_array($firstSessionId, array_column($sessions, 'public_id'), true));
        self::assertFalse(in_array($secondSessionId, array_column($sessions, 'public_id'), true));

        $revokeResponse = $kernel->handle(Request::create(
            '/inventory-revoke',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($revokePayload['revoked']);
        self::assertIsString($revokePayload['target_public_id'] ?? null);
        self::assertNotSame($revokePayload['current_public_id'], $revokePayload['target_public_id']);

        $oldSessionResponse = $kernel->handle(Request::create(
            '/inventory-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $oldPayload = json_decode($oldSessionResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $oldSessionResponse->statusCode());
        self::assertSame('auth.revoked_session', $oldPayload['reason_code'] ?? null);

        $currentInventoryResponse = $kernel->handle(Request::create(
            '/inventory-sessions',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
        ));
        $currentInventoryPayload = json_decode($currentInventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $currentInventoryPayload['sessions'] ?? []);
        self::assertSame($revokePayload['current_public_id'], $currentInventoryPayload['current_public_id'] ?? null);
    }

    public function test_auth_manager_can_revoke_other_sessions_without_revoking_the_current_one(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 33,
                'identifier' => 'session-revoke-others@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/revoke-others-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'session-revoke-others@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->post('/revoke-others', function (): array {
            return [
                'revoked' => auth()->revokeOtherSessions(),
                'remaining' => count(auth()->sessions()),
                'current_public_id' => auth()->currentSession()?->publicId,
            ];
        });
        $router->get('/revoke-others-me', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        })->middleware('auth');

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create('/revoke-others-login'));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;
        $secondLogin = $kernel->handle(Request::create('/revoke-others-login'));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);
        self::assertIsString($secondSessionId);

        $revokeResponse = $kernel->handle(Request::create(
            '/revoke-others',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $revokePayload['revoked']);
        self::assertSame(1, $revokePayload['remaining']);
        self::assertStringStartsWith('sess_pub_', $revokePayload['current_public_id'] ?? '');

        $oldSessionResponse = $kernel->handle(Request::create(
            '/revoke-others-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $oldPayload = json_decode($oldSessionResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(401, $oldSessionResponse->statusCode());
        self::assertSame('auth.revoked_session', $oldPayload['reason_code'] ?? null);

        $currentSessionResponse = $kernel->handle(Request::create(
            '/revoke-others-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
        ));
        $currentPayload = json_decode($currentSessionResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($currentPayload['check']);
        self::assertSame('33', (string) $currentPayload['id']);
    }

    public function test_auth_manager_requires_fresh_authentication_for_remote_session_revocation(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 35,
                'identifier' => 'fresh-remote-revoke@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.management.fresh_auth_window', 300);

        $router = $app->make(Router::class);
        $router->get('/fresh-remote-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'fresh-remote-revoke@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->post('/fresh-remote-revoke', function (): array {
            $target = null;

            foreach (auth()->sessions() as $session) {
                if (! $session->current) {
                    $target = $session;
                    break;
                }
            }

            return [
                'revoked' => $target !== null ? auth()->revokeSession($target->publicId) : false,
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create('/fresh-remote-login'));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;
        $secondLogin = $kernel->handle(Request::create('/fresh-remote-login'));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);
        self::assertIsString($secondSessionId);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($secondSessionId);

        self::assertInstanceOf(AuthenticationSession::class, $currentSession);

        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;

        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $response = $kernel->handle(Request::create(
            '/fresh-remote-revoke',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->statusCode());
        self::assertSame('auth.fresh_authentication_required', $payload['reason_code'] ?? null);
        self::assertSame('session_revocation', $payload['operation'] ?? null);
        self::assertSame('required', $response->headers()['X-Auth-Reauthenticate'] ?? null);
        self::assertSame('300', $response->headers()['X-Auth-Fresh-Window'] ?? null);
    }

    public function test_auth_manager_allows_revoking_the_current_session_without_fresh_authentication(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 36,
                'identifier' => 'fresh-self-revoke@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.management.fresh_auth_window', 300);

        $router = $app->make(Router::class);
        $router->get('/fresh-self-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'fresh-self-revoke@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->post('/fresh-self-revoke', function (): array {
            $current = auth()->currentSession();

            return [
                'current_public_id' => $current?->publicId,
                'revoked' => $current !== null ? auth()->revokeSession($current->publicId) : false,
            ];
        });
        $router->get('/fresh-self-me', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        })->middleware('auth');

        $kernel = $app->make(HttpKernel::class);
        $login = $kernel->handle(Request::create('/fresh-self-login'));
        $sessionId = $login->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($sessionId);

        self::assertInstanceOf(AuthenticationSession::class, $currentSession);

        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;

        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $revokeResponse = $kernel->handle(Request::create(
            '/fresh-self-revoke',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $revokeResponse->statusCode());
        self::assertTrue($revokePayload['revoked']);
        self::assertStringStartsWith('sess_pub_', $revokePayload['current_public_id'] ?? '');
        self::assertSame('cleared', $revokeResponse->headers()['X-Auth-Session'] ?? null);

        $afterResponse = $kernel->handle(Request::create(
            '/fresh-self-me',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $afterPayload = json_decode($afterResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $afterResponse->statusCode());
        self::assertSame('auth.revoked_session', $afterPayload['reason_code'] ?? null);
    }

    public function test_auth_manager_inventory_exposes_safe_session_metadata_and_updates_last_activity_on_recovery(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 34,
                'identifier' => 'session-metadata@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/metadata-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'session-metadata@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/metadata-sessions', function (): array {
            return [
                'sessions' => array_map(static fn ($session): array => [
                    'public_id' => $session->publicId,
                    'label' => $session->label,
                    'client_family' => $session->clientFamily,
                    'client_platform' => $session->clientPlatform,
                    'device_kind' => $session->deviceKind,
                    'device_reference' => $session->deviceReference,
                    'device_trust_state' => $session->deviceTrustState,
                    'ip_prefix' => $session->ipPrefix,
                    'last_activity_at' => $session->lastActivityAt,
                    'issued_at' => $session->issuedAt,
                    'can_revoke' => $session->canRevoke,
                    'requires_reauthentication' => $session->requiresReauthentication,
                    'revocation_scope' => $session->revocationScope,
                    'revocation_mode' => $session->revocationMode,
                ], auth()->sessions()),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create(
            '/metadata-login',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'REMOTE_ADDR' => '203.0.113.42',
            ],
        ));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $inventoryResponse = $kernel->handle(Request::create(
            '/metadata-sessions',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'REMOTE_ADDR' => '203.0.113.42',
            ],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $session = $inventoryPayload['sessions'][0] ?? null;

        self::assertIsArray($session);
        self::assertStringStartsWith('sess_pub_', $session['public_id'] ?? '');
        self::assertSame('Chrome', $session['client_family'] ?? null);
        self::assertSame('Windows', $session['client_platform'] ?? null);
        self::assertSame('desktop', $session['device_kind'] ?? null);
        self::assertIsString($session['device_reference'] ?? null);
        self::assertStringStartsWith('devref_', $session['device_reference'] ?? '');
        self::assertSame('unknown', $session['device_trust_state'] ?? null);
        self::assertSame('203.0.113.x', $session['ip_prefix'] ?? null);
        self::assertSame('Chrome on Windows from 203.0.113.x', $session['label'] ?? null);
        self::assertIsInt($session['issued_at'] ?? null);
        self::assertIsInt($session['last_activity_at'] ?? null);
        self::assertGreaterThanOrEqual($session['issued_at'], $session['last_activity_at']);
        self::assertTrue((bool) ($session['can_revoke'] ?? false));
        self::assertFalse((bool) ($session['requires_reauthentication'] ?? true));
        self::assertSame('current', $session['revocation_scope'] ?? null);
        self::assertSame('direct', $session['revocation_mode'] ?? null);
        self::assertNotSame('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0', $session['client_family'] ?? null);
        self::assertNotSame('203.0.113.42', $session['ip_prefix'] ?? null);
    }

    public function test_auth_manager_inventory_exposes_revocation_hints_for_remote_sessions(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 37,
                'identifier' => 'inventory-hints@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.management.fresh_auth_window', 300);

        $router = $app->make(Router::class);
        $router->get('/inventory-hints-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'inventory-hints@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/inventory-hints-sessions', function (): array {
            return [
                'sessions' => array_map(static fn ($session): array => [
                    'public_id' => $session->publicId,
                    'current' => $session->current,
                    'device_kind' => $session->deviceKind,
                    'client_platform' => $session->clientPlatform,
                'device_reference' => $session->deviceReference,
                'device_trust_state' => $session->deviceTrustState,
                    'can_revoke' => $session->canRevoke,
                    'requires_reauthentication' => $session->requiresReauthentication,
                'revocation_scope' => $session->revocationScope,
                'revocation_mode' => $session->revocationMode,
                ], auth()->sessions()),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create(
            '/inventory-hints-login',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile Safari/604.1',
                'REMOTE_ADDR' => '203.0.113.12',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;
        $secondLogin = $kernel->handle(Request::create(
            '/inventory-hints-login',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'REMOTE_ADDR' => '203.0.113.33',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);
        self::assertIsString($secondSessionId);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($secondSessionId);

        self::assertInstanceOf(AuthenticationSession::class, $currentSession);

        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;

        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $response = $kernel->handle(Request::create(
            '/inventory-hints-sessions',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'REMOTE_ADDR' => '203.0.113.33',
            ],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
        $sessions = $payload['sessions'] ?? [];

        self::assertCount(2, $sessions);

        $current = array_values(array_filter($sessions, static fn (array $session): bool => (bool) ($session['current'] ?? false)))[0] ?? null;
        $remote = array_values(array_filter($sessions, static fn (array $session): bool => ! (bool) ($session['current'] ?? false)))[0] ?? null;

        self::assertIsArray($current);
        self::assertIsArray($remote);
        self::assertTrue((bool) ($current['can_revoke'] ?? false));
        self::assertFalse((bool) ($current['requires_reauthentication'] ?? true));
        self::assertSame('desktop', $current['device_kind'] ?? null);
        self::assertSame('Windows', $current['client_platform'] ?? null);
        self::assertIsString($current['device_reference'] ?? null);
        self::assertSame('unknown', $current['device_trust_state'] ?? null);
        self::assertSame('current', $current['revocation_scope'] ?? null);
        self::assertSame('direct', $current['revocation_mode'] ?? null);

        self::assertTrue((bool) ($remote['can_revoke'] ?? false));
        self::assertTrue((bool) ($remote['requires_reauthentication'] ?? false));
        self::assertSame('mobile', $remote['device_kind'] ?? null);
        self::assertSame('iOS', $remote['client_platform'] ?? null);
        self::assertIsString($remote['device_reference'] ?? null);
        self::assertSame('unknown', $remote['device_trust_state'] ?? null);
        self::assertSame('peer', $remote['revocation_scope'] ?? null);
        self::assertSame('fresh_auth_required', $remote['revocation_mode'] ?? null);
        self::assertNotSame($current['device_reference'] ?? null, $remote['device_reference'] ?? null);
    }

    public function test_auth_manager_can_trust_and_list_the_current_device_after_multi_factor_authentication(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 38,
                'identifier' => 'trusted-device@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/trusted-device-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'trusted-device@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/trusted-device-enroll', function (): array {
            return [
                'trusted' => auth()->trustCurrentDevice('Office Laptop'),
                'sessions' => array_map(static fn ($session): array => [
                    'device_trust_state' => $session->deviceTrustState,
                    'device_reference' => $session->deviceReference,
                    'current' => $session->current,
                ], auth()->sessions()),
                'trusted_devices' => array_map(static fn ($device): array => [
                    'public_id' => $device->publicId,
                    'device_reference' => $device->deviceReference,
                    'trust_state' => $device->trustState,
                    'current' => $device->current,
                    'can_forget' => $device->canForget,
                    'requires_reauthentication' => $device->requiresReauthentication,
                    'revocation_scope' => $device->revocationScope,
                    'revocation_mode' => $device->revocationMode,
                    'label' => $device->label,
                    'client_platform' => $device->clientPlatform,
                    'device_kind' => $device->deviceKind,
                ], auth()->trustedDevices()),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create(
            '/trusted-device-login',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.10',
            ],
        ));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $enrollResponse = $kernel->handle(Request::create(
            '/trusted-device-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.10',
            ],
        ));
        $payload = json_decode($enrollResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $session = $payload['sessions'][0] ?? null;
        $trustedDevice = $payload['trusted_devices'][0] ?? null;

        self::assertTrue($payload['trusted']);
        self::assertIsArray($session);
        self::assertSame('trusted', $session['device_trust_state'] ?? null);
        self::assertStringStartsWith('devref_', $session['device_reference'] ?? '');

        self::assertIsArray($trustedDevice);
        self::assertStringStartsWith('tdv_', $trustedDevice['public_id'] ?? '');
        self::assertSame($session['device_reference'] ?? null, $trustedDevice['device_reference'] ?? null);
        self::assertSame('trusted', $trustedDevice['trust_state'] ?? null);
        self::assertTrue((bool) ($trustedDevice['current'] ?? false));
        self::assertTrue((bool) ($trustedDevice['can_forget'] ?? false));
        self::assertFalse((bool) ($trustedDevice['requires_reauthentication'] ?? true));
        self::assertSame('current', $trustedDevice['revocation_scope'] ?? null);
        self::assertSame('direct', $trustedDevice['revocation_mode'] ?? null);
        self::assertSame('Office Laptop', $trustedDevice['label'] ?? null);
        self::assertSame('Windows', $trustedDevice['client_platform'] ?? null);
        self::assertSame('desktop', $trustedDevice['device_kind'] ?? null);
    }

    public function test_auth_manager_requires_multi_factor_to_trust_current_device_and_can_forget_it(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 39,
                'identifier' => 'trusted-device-step-up@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/trusted-step-up-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'trusted-device-step-up@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->post('/trusted-step-up-elevate', function (): array {
            return ['ok' => auth()->stepUp([
                'second_factor' => '654321',
            ])];
        });
        $router->post('/trusted-step-up-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->post('/trusted-step-up-forget', function (): array {
            $device = auth()->trustedDevices()[0] ?? null;

            return [
                'forgotten' => $device !== null ? auth()->forgetTrustedDevice($device->publicId) : false,
                'current_trust_state' => auth()->currentSession()?->deviceTrustState,
                'trusted_devices_count' => count(auth()->trustedDevices()),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create(
            '/trusted-step-up-login',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.55',
            ],
        ));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $preMfaEnrollResponse = $kernel->handle(Request::create(
            '/trusted-step-up-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.55',
            ],
        ));
        $preMfaPayload = json_decode($preMfaEnrollResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($preMfaPayload['trusted']);

        $stepUpResponse = $kernel->handle(Request::create(
            '/trusted-step-up-elevate',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.55',
            ],
        ));
        $elevatedSessionId = $stepUpResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($elevatedSessionId);

        $postMfaEnrollResponse = $kernel->handle(Request::create(
            '/trusted-step-up-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $elevatedSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.55',
            ],
        ));
        $postMfaPayload = json_decode($postMfaEnrollResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($postMfaPayload['trusted']);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($elevatedSessionId);

        self::assertInstanceOf(AuthenticationSession::class, $currentSession);

        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;

        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $forgetResponse = $kernel->handle(Request::create(
            '/trusted-step-up-forget',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $elevatedSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.55',
            ],
        ));
        $forgetPayload = json_decode($forgetResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($forgetPayload['forgotten']);
        self::assertSame('unknown', $forgetPayload['current_trust_state'] ?? null);
        self::assertSame(0, $forgetPayload['trusted_devices_count'] ?? null);
    }

    public function test_auth_manager_requires_fresh_authentication_to_forget_remote_trusted_devices(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 46,
                'identifier' => 'trusted-remote-forget@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.trusted_devices.management.fresh_auth_window', 300);

        $router = $app->make(Router::class);
        $router->post('/trusted-remote-forget-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'trusted-remote-forget@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/trusted-remote-forget-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->get('/trusted-remote-forget-inventory', function (): array {
            return [
                'trusted_devices' => array_map(static fn ($device): array => [
                    'public_id' => $device->publicId,
                    'current' => $device->current,
                    'can_forget' => $device->canForget,
                    'requires_reauthentication' => $device->requiresReauthentication,
                    'revocation_scope' => $device->revocationScope,
                    'revocation_mode' => $device->revocationMode,
                ], auth()->trustedDevices()),
            ];
        });
        $router->post('/trusted-remote-forget', function (): array {
            $target = null;

            foreach (auth()->trustedDevices() as $device) {
                if (! $device->current) {
                    $target = $device;
                    break;
                }
            }

            return [
                'forgotten' => $target !== null ? auth()->forgetTrustedDevice($target->publicId) : false,
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create(
            '/trusted-remote-forget-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.60',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);

        $firstEnroll = $kernel->handle(Request::create(
            '/trusted-remote-forget-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.60',
            ],
        ));
        $firstEnrollPayload = json_decode($firstEnroll->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($firstEnrollPayload['trusted']);

        $secondLogin = $kernel->handle(Request::create(
            '/trusted-remote-forget-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.61',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($secondSessionId);

        $secondEnroll = $kernel->handle(Request::create(
            '/trusted-remote-forget-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.61',
            ],
        ));
        $secondEnrollPayload = json_decode($secondEnroll->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($secondEnrollPayload['trusted']);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($secondSessionId);

        self::assertInstanceOf(AuthenticationSession::class, $currentSession);

        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;

        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $inventoryResponse = $kernel->handle(Request::create(
            '/trusted-remote-forget-inventory',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.61',
            ],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $trustedDevices = $inventoryPayload['trusted_devices'] ?? [];
        $current = array_values(array_filter($trustedDevices, static fn (array $device): bool => (bool) ($device['current'] ?? false)))[0] ?? null;
        $remote = array_values(array_filter($trustedDevices, static fn (array $device): bool => ! (bool) ($device['current'] ?? false)))[0] ?? null;

        self::assertIsArray($current);
        self::assertIsArray($remote);
        self::assertTrue((bool) ($current['can_forget'] ?? false));
        self::assertFalse((bool) ($current['requires_reauthentication'] ?? true));
        self::assertSame('current', $current['revocation_scope'] ?? null);
        self::assertSame('direct', $current['revocation_mode'] ?? null);
        self::assertTrue((bool) ($remote['can_forget'] ?? false));
        self::assertTrue((bool) ($remote['requires_reauthentication'] ?? false));
        self::assertSame('peer', $remote['revocation_scope'] ?? null);
        self::assertSame('fresh_auth_required', $remote['revocation_mode'] ?? null);

        $forgetResponse = $kernel->handle(Request::create(
            '/trusted-remote-forget',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.61',
            ],
        ));
        $forgetPayload = json_decode($forgetResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $forgetResponse->statusCode());
        self::assertSame('auth.fresh_authentication_required', $forgetPayload['reason_code'] ?? null);
        self::assertSame('trusted_device_revocation', $forgetPayload['operation'] ?? null);
        self::assertSame('required', $forgetResponse->headers()['X-Auth-Reauthenticate'] ?? null);
        self::assertSame('300', $forgetResponse->headers()['X-Auth-Fresh-Window'] ?? null);
    }

    public function test_auth_manager_can_issue_trusted_device_cookie_alongside_session_cookie_and_reduce_mfa_challenge(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 40,
                'identifier' => 'trusted-cookie-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'mfa_required' => true,
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/trusted-cookie-login-and-enroll', function (): array {
            auth()->attemptOrFail([
                'identifier' => 'trusted-cookie-user@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ]);

            return [
                'trusted' => auth()->trustCurrentDevice('Primary laptop'),
                'session_public_id' => auth()->currentSession()?->publicId,
            ];
        });
        $router->post('/trusted-cookie-password-login', function (): array {
            return [
                'ok' => auth()->attempt([
                    'identifier' => 'trusted-cookie-user@example.com',
                    'password' => 'secret-123',
                ]),
                'strength' => auth()->context()?->authenticationStrength()->name,
                'assurance_profile' => auth()->context()?->authenticationAssuranceProfile(),
                'trusted_device_credential_present' => auth()->context()?->trustedDeviceCredentialPresent(),
                'trusted_device_public_id' => auth()->context()?->trustedDevicePublicId(),
                'device_trust_state' => auth()->currentSession()?->deviceTrustState,
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $enrollResponse = $kernel->handle(Request::create(
            '/trusted-cookie-login-and-enroll',
            'POST',
            server: [
                'HTTP_HOST' => 'voltstack.test',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.77',
            ],
        ));
        $enrollPayload = json_decode($enrollResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $setCookie = $enrollResponse->headers()['Set-Cookie'] ?? null;

        self::assertTrue($enrollPayload['trusted']);
        self::assertIsArray($setCookie);
        self::assertCount(2, $setCookie);

        $sessionId = $this->extractCookieValue($setCookie, AuthenticationHttpState::SESSION_COOKIE_NAME);
        $trustedDeviceCredential = $this->extractCookieValue($setCookie, AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME);

        self::assertIsString($sessionId);
        self::assertIsString($trustedDeviceCredential);
        self::assertStringStartsWith('tdv_', $trustedDeviceCredential);
        self::assertStringContainsString('.', $trustedDeviceCredential);

        $passwordOnlyResponse = $kernel->handle(Request::create(
            '/trusted-cookie-password-login',
            'POST',
            cookies: [
                AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME => $trustedDeviceCredential,
            ],
            server: [
                'HTTP_HOST' => 'voltstack.test',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.77',
            ],
        ));
        $payload = json_decode($passwordOnlyResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $rotatedTrustedDeviceCredential = $this->extractCookieValue(
            $passwordOnlyResponse->headers()['Set-Cookie'] ?? null,
            AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME,
        );

        self::assertTrue($payload['ok']);
        self::assertSame('Password', $payload['strength'] ?? null);
        self::assertSame('single_factor', $payload['assurance_profile'] ?? null);
        self::assertTrue((bool) ($payload['trusted_device_credential_present'] ?? false));
        self::assertSame('trusted', $payload['device_trust_state'] ?? null);
        self::assertIsString($payload['trusted_device_public_id'] ?? null);
        self::assertStringStartsWith('tdv_', $payload['trusted_device_public_id'] ?? '');
        self::assertIsString($rotatedTrustedDeviceCredential);
        self::assertNotSame($trustedDeviceCredential, $rotatedTrustedDeviceCredential);
        self::assertSame(
            strtok($trustedDeviceCredential, '.'),
            strtok($rotatedTrustedDeviceCredential, '.'),
        );
    }

    public function test_auth_manager_does_not_reduce_mfa_challenge_when_trusted_device_cookie_fingerprint_changes(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 44,
                'identifier' => 'trusted-cookie-mismatch@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'mfa_required' => true,
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/trusted-cookie-mismatch-enroll', function (): array {
            auth()->attemptOrFail([
                'identifier' => 'trusted-cookie-mismatch@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ]);

            return [
                'trusted' => auth()->trustCurrentDevice(),
            ];
        });
        $router->post('/trusted-cookie-mismatch-login', function (): void {
            auth()->attemptOrFail([
                'identifier' => 'trusted-cookie-mismatch@example.com',
                'password' => 'secret-123',
            ]);
        });

        $kernel = $app->make(HttpKernel::class);
        $enrollResponse = $kernel->handle(Request::create(
            '/trusted-cookie-mismatch-enroll',
            'POST',
            server: [
                'HTTP_HOST' => 'voltstack.test',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.44',
            ],
        ));
        $trustedDeviceCredential = $this->extractCookieValue(
            $enrollResponse->headers()['Set-Cookie'] ?? null,
            AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME,
        );

        self::assertIsString($trustedDeviceCredential);

        $response = $kernel->handle(Request::create(
            '/trusted-cookie-mismatch-login',
            'POST',
            cookies: [
                AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME => $trustedDeviceCredential,
            ],
            server: [
                'HTTP_HOST' => 'voltstack.test',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '203.0.113.44',
            ],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
        $setCookieHeader = $response->headers()['Set-Cookie'] ?? null;
        $setCookieValues = is_array($setCookieHeader) ? $setCookieHeader : (is_string($setCookieHeader) ? [$setCookieHeader] : []);
        $clearedCookie = false;

        foreach ($setCookieValues as $value) {
            if (str_contains($value, 'Max-Age=0')) {
                $clearedCookie = true;
                break;
            }
        }

        self::assertSame(401, $response->statusCode());
        self::assertSame('Second factor verification is required.', $payload['message'] ?? null);
        self::assertSame('auth.second_factor_required', $response->headers()['X-Volt-Error-Code'] ?? null);
        self::assertTrue($clearedCookie);
    }

    public function test_auth_manager_revokes_trusted_device_when_previous_rotated_cookie_is_replayed(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 45,
                'identifier' => 'trusted-cookie-replay@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'mfa_required' => true,
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/trusted-cookie-replay-enroll', function (): array {
            auth()->attemptOrFail([
                'identifier' => 'trusted-cookie-replay@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ]);

            return [
                'trusted' => auth()->trustCurrentDevice('Replay test laptop'),
            ];
        });
        $router->post('/trusted-cookie-replay-login', function (): array {
            return [
                'ok' => auth()->attempt([
                    'identifier' => 'trusted-cookie-replay@example.com',
                    'password' => 'secret-123',
                ]),
            ];
        });
        $router->get('/trusted-cookie-replay-state', function (): array {
            return [
                'check' => auth()->check(),
                'current_trust_state' => auth()->currentSession()?->deviceTrustState,
                'trusted_devices_count' => count(auth()->trustedDevices()),
                'trusted_device_public_id' => auth()->context()?->trustedDevicePublicId(),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $enrollResponse = $kernel->handle(Request::create(
            '/trusted-cookie-replay-enroll',
            'POST',
            server: [
                'HTTP_HOST' => 'voltstack.test',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.45',
            ],
        ));
        $initialTrustedDeviceCredential = $this->extractCookieValue(
            $enrollResponse->headers()['Set-Cookie'] ?? null,
            AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME,
        );

        self::assertIsString($initialTrustedDeviceCredential);

        $loginResponse = $kernel->handle(Request::create(
            '/trusted-cookie-replay-login',
            'POST',
            cookies: [
                AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME => $initialTrustedDeviceCredential,
            ],
            server: [
                'HTTP_HOST' => 'voltstack.test',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.45',
            ],
        ));
        $sessionId = $this->extractCookieValue(
            $loginResponse->headers()['Set-Cookie'] ?? null,
            AuthenticationHttpState::SESSION_COOKIE_NAME,
        );
        $rotatedTrustedDeviceCredential = $this->extractCookieValue(
            $loginResponse->headers()['Set-Cookie'] ?? null,
            AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME,
        );

        self::assertIsString($sessionId);
        self::assertIsString($rotatedTrustedDeviceCredential);
        self::assertNotSame($initialTrustedDeviceCredential, $rotatedTrustedDeviceCredential);

        $replayResponse = $kernel->handle(Request::create(
            '/trusted-cookie-replay-state',
            'GET',
            cookies: [
                AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId,
                AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME => $initialTrustedDeviceCredential,
            ],
            server: [
                'HTTP_HOST' => 'voltstack.test',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.45',
            ],
        ));
        $replayPayload = json_decode($replayResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $setCookieHeader = $replayResponse->headers()['Set-Cookie'] ?? null;
        $setCookieValues = is_array($setCookieHeader) ? $setCookieHeader : (is_string($setCookieHeader) ? [$setCookieHeader] : []);
        $clearedCookie = false;

        foreach ($setCookieValues as $value) {
            if (str_contains($value, AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME . '=') && str_contains($value, 'Max-Age=0')) {
                $clearedCookie = true;
                break;
            }
        }

        self::assertSame(200, $replayResponse->statusCode());
        self::assertTrue($replayPayload['check']);
        self::assertSame('unknown', $replayPayload['current_trust_state'] ?? null);
        self::assertSame(0, $replayPayload['trusted_devices_count'] ?? null);
        self::assertNull($replayPayload['trusted_device_public_id'] ?? null);
        self::assertTrue($clearedCookie);
    }

    public function test_auth_manager_devices_inventory_aggregates_sessions_and_trusted_devices_by_device_reference(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 47,
                'identifier' => 'device-center@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/device-center-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'device-center@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/device-center-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice('Current work laptop')];
        });
        $router->get('/device-center-inventory', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'trust_state' => $device->trustState,
                    'session_count' => $device->sessionCount,
                    'current_session_count' => $device->currentSessionCount,
                    'current' => $device->current,
                    'has_trusted_device' => $device->hasTrustedDevice,
                    'trusted_device_public_id' => $device->trustedDevicePublicId,
                    'session_public_ids' => $device->sessionPublicIds,
                    'can_revoke_sessions' => $device->canRevokeSessions,
                    'can_forget_trusted_device' => $device->canForgetTrustedDevice,
                    'requires_reauthentication' => $device->requiresReauthentication,
                    'management_scope' => $device->managementScope,
                    'management_mode' => $device->managementMode,
                    'management_authority' => $device->managementAuthority,
                    'management_ownership_proof' => $device->managementOwnershipProof,
                    'management_sensitivity' => $device->managementSensitivity,
                    'management_reason_code' => $device->managementReasonCode,
                    'management_actor_governed' => $device->managementActorGoverned,
                    'management_actor_authorized' => $device->managementActorAuthorized,
                    'management_actor_authorization_mode' => $device->managementActorAuthorizationMode,
                    'management_actor_authorization_reason_code' => $device->managementActorAuthorizationReasonCode,
                    'management_actor_authority' => $device->managementActorAuthority,
                    'management_actor_ownership_proof' => $device->managementActorOwnershipProof,
                    'management_actor_claims_source' => $device->managementActorClaimsSource,
                    'management_actor_privilege_level' => $device->managementActorPrivilegeLevel,
                    'management_actor_scopes' => $device->managementActorScopes,
                    'management_actor_can_manage_sessions' => $device->managementActorCanManageSessions,
                    'management_actor_session_authorization_mode' => $device->managementActorSessionAuthorizationMode,
                    'management_actor_session_authorization_reason_code' => $device->managementActorSessionAuthorizationReasonCode,
                    'management_actor_can_manage_trusted_devices' => $device->managementActorCanManageTrustedDevices,
                    'management_actor_trusted_device_authorization_mode' => $device->managementActorTrustedDeviceAuthorizationMode,
                    'management_actor_trusted_device_authorization_reason_code' => $device->managementActorTrustedDeviceAuthorizationReasonCode,
                    'management_actor_target_relation' => $device->managementActorTargetRelation,
                    'management_actor_target_reason_code' => $device->managementActorTargetReasonCode,
                    'management_target_identity' => $device->managementTargetIdentity,
                    'management_target_type' => $device->managementTargetType,
                    'management_target_matches_current_identity' => $device->managementTargetMatchesCurrentIdentity,
                    'client_platform' => $device->clientPlatform,
                    'device_kind' => $device->deviceKind,
                ], auth()->devices()),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create(
            '/device-center-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.70',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);

        $secondLogin = $kernel->handle(Request::create(
            '/device-center-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.71',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($secondSessionId);

        $enrollResponse = $kernel->handle(Request::create(
            '/device-center-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.71',
            ],
        ));
        $enrollPayload = json_decode($enrollResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($enrollPayload['trusted']);

        $inventoryResponse = $kernel->handle(Request::create(
            '/device-center-inventory',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.71',
            ],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $devices = $inventoryPayload['devices'] ?? [];

        self::assertCount(2, $devices);

        $current = array_values(array_filter($devices, static fn (array $device): bool => (bool) ($device['current'] ?? false)))[0] ?? null;
        $remote = array_values(array_filter($devices, static fn (array $device): bool => ! (bool) ($device['current'] ?? false)))[0] ?? null;

        self::assertIsArray($current);
        self::assertIsArray($remote);

        self::assertSame('trusted', $current['trust_state'] ?? null);
        self::assertSame(1, $current['session_count'] ?? null);
        self::assertSame(1, $current['current_session_count'] ?? null);
        self::assertTrue((bool) ($current['has_trusted_device'] ?? false));
        self::assertIsString($current['trusted_device_public_id'] ?? null);
        self::assertStringStartsWith('tdv_', $current['trusted_device_public_id'] ?? '');
        self::assertCount(1, $current['session_public_ids'] ?? []);
        self::assertTrue((bool) ($current['can_revoke_sessions'] ?? false));
        self::assertTrue((bool) ($current['can_forget_trusted_device'] ?? false));
        self::assertFalse((bool) ($current['requires_reauthentication'] ?? true));
        self::assertSame('current', $current['management_scope'] ?? null);
        self::assertSame('direct', $current['management_mode'] ?? null);
        self::assertSame('session_owner', $current['management_authority'] ?? null);
        self::assertSame('current_session', $current['management_ownership_proof'] ?? null);
        self::assertSame('standard', $current['management_sensitivity'] ?? null);
        self::assertSame('trusted_device_management', $current['management_reason_code'] ?? null);
        self::assertSame('47', $current['management_target_identity'] ?? null);
        self::assertSame('user', $current['management_target_type'] ?? null);
        self::assertTrue((bool) ($current['management_target_matches_current_identity'] ?? false));
        self::assertSame('session_owner', $current['management_actor_authority'] ?? null);
        self::assertSame('current_session', $current['management_actor_ownership_proof'] ?? null);
        self::assertSame('self_service_defaults', $current['management_actor_claims_source'] ?? null);
        self::assertSame('self_service', $current['management_actor_privilege_level'] ?? null);
        self::assertContains('current_device_management', $current['management_actor_scopes'] ?? []);
        self::assertFalse((bool) ($current['management_actor_can_manage_sessions'] ?? true));
        self::assertFalse((bool) ($current['management_actor_can_manage_trusted_devices'] ?? true));
        self::assertSame('not_administrative_actor', $current['management_actor_session_authorization_reason_code'] ?? null);
        self::assertSame('not_administrative_actor', $current['management_actor_trusted_device_authorization_reason_code'] ?? null);
        self::assertSame('self', $current['management_actor_target_relation'] ?? null);
        self::assertSame('current_identity_target', $current['management_actor_target_reason_code'] ?? null);
        self::assertFalse((bool) ($current['management_actor_governed'] ?? true));
        self::assertFalse((bool) ($current['management_actor_authorized'] ?? true));
        self::assertNull($current['management_actor_authorization_mode'] ?? null);
        self::assertSame('not_administrative_actor', $current['management_actor_authorization_reason_code'] ?? null);
        self::assertSame('Windows', $current['client_platform'] ?? null);
        self::assertSame('desktop', $current['device_kind'] ?? null);

        self::assertSame('unknown', $remote['trust_state'] ?? null);
        self::assertSame(1, $remote['session_count'] ?? null);
        self::assertSame(0, $remote['current_session_count'] ?? null);
        self::assertFalse((bool) ($remote['has_trusted_device'] ?? true));
        self::assertNull($remote['trusted_device_public_id'] ?? null);
        self::assertCount(1, $remote['session_public_ids'] ?? []);
        self::assertTrue((bool) ($remote['can_revoke_sessions'] ?? false));
        self::assertFalse((bool) ($remote['can_forget_trusted_device'] ?? true));
        self::assertFalse((bool) ($remote['requires_reauthentication'] ?? true));
        self::assertSame('peer', $remote['management_scope'] ?? null);
        self::assertSame('direct', $remote['management_mode'] ?? null);
        self::assertSame('identity_owner', $remote['management_authority'] ?? null);
        self::assertSame('identity_session', $remote['management_ownership_proof'] ?? null);
        self::assertSame('elevated', $remote['management_sensitivity'] ?? null);
        self::assertSame('remote_device_management', $remote['management_reason_code'] ?? null);
        self::assertSame('47', $remote['management_target_identity'] ?? null);
        self::assertSame('user', $remote['management_target_type'] ?? null);
        self::assertTrue((bool) ($remote['management_target_matches_current_identity'] ?? false));
        self::assertSame('session_owner', $remote['management_actor_authority'] ?? null);
        self::assertSame('current_session', $remote['management_actor_ownership_proof'] ?? null);
        self::assertSame('self_service_defaults', $remote['management_actor_claims_source'] ?? null);
        self::assertSame('self_service', $remote['management_actor_privilege_level'] ?? null);
        self::assertContains('identity_device_management', $remote['management_actor_scopes'] ?? []);
        self::assertFalse((bool) ($remote['management_actor_can_manage_sessions'] ?? true));
        self::assertFalse((bool) ($remote['management_actor_can_manage_trusted_devices'] ?? true));
        self::assertSame('not_administrative_actor', $remote['management_actor_session_authorization_reason_code'] ?? null);
        self::assertSame('not_administrative_actor', $remote['management_actor_trusted_device_authorization_reason_code'] ?? null);
        self::assertSame('self', $remote['management_actor_target_relation'] ?? null);
        self::assertSame('current_identity_target', $remote['management_actor_target_reason_code'] ?? null);
        self::assertFalse((bool) ($remote['management_actor_governed'] ?? true));
        self::assertFalse((bool) ($remote['management_actor_authorized'] ?? true));
        self::assertNull($remote['management_actor_authorization_mode'] ?? null);
        self::assertSame('not_administrative_actor', $remote['management_actor_authorization_reason_code'] ?? null);
        self::assertSame('macOS', $remote['client_platform'] ?? null);
        self::assertSame('desktop', $remote['device_kind'] ?? null);
    }

    public function test_auth_manager_devices_inventory_projects_governed_actor_hints_from_current_context(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 48,
                'identifier' => 'governed-device-center@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => [
                    'security_center_export',
                    'admin_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/governed-device-center-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'governed-device-center@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->get('/governed-device-center-inventory', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'current' => $device->current,
                    'management_actor_governed' => $device->managementActorGoverned,
                    'management_actor_authorized' => $device->managementActorAuthorized,
                    'management_actor_authorization_mode' => $device->managementActorAuthorizationMode,
                    'management_actor_authorization_reason_code' => $device->managementActorAuthorizationReasonCode,
                    'management_actor_authority' => $device->managementActorAuthority,
                    'management_actor_ownership_proof' => $device->managementActorOwnershipProof,
                    'management_actor_claims_source' => $device->managementActorClaimsSource,
                    'management_actor_privilege_level' => $device->managementActorPrivilegeLevel,
                    'management_actor_scopes' => $device->managementActorScopes,
                    'management_actor_can_manage_sessions' => $device->managementActorCanManageSessions,
                    'management_actor_session_authorization_mode' => $device->managementActorSessionAuthorizationMode,
                    'management_actor_session_authorization_reason_code' => $device->managementActorSessionAuthorizationReasonCode,
                    'management_actor_can_manage_trusted_devices' => $device->managementActorCanManageTrustedDevices,
                    'management_actor_trusted_device_authorization_mode' => $device->managementActorTrustedDeviceAuthorizationMode,
                    'management_actor_trusted_device_authorization_reason_code' => $device->managementActorTrustedDeviceAuthorizationReasonCode,
                    'management_actor_target_relation' => $device->managementActorTargetRelation,
                    'management_actor_target_reason_code' => $device->managementActorTargetReasonCode,
                ], auth()->devices()),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create(
            '/governed-device-center-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.72',
            ],
        ));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $inventoryResponse = $kernel->handle(Request::create(
            '/governed-device-center-inventory',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.72',
            ],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $devices = $inventoryPayload['devices'] ?? [];
        $current = $devices[0] ?? null;

        self::assertCount(1, $devices);
        self::assertIsArray($current);
        self::assertTrue((bool) ($current['current'] ?? false));
        self::assertTrue((bool) ($current['management_actor_governed'] ?? false));
        self::assertTrue((bool) ($current['management_actor_authorized'] ?? false));
        self::assertSame('direct_admin', $current['management_actor_authorization_mode'] ?? null);
        self::assertNull($current['management_actor_authorization_reason_code'] ?? null);
        self::assertSame('administrative_actor', $current['management_actor_authority'] ?? null);
        self::assertSame('privileged_session', $current['management_actor_ownership_proof'] ?? null);
        self::assertSame('identity_attributes', $current['management_actor_claims_source'] ?? null);
        self::assertSame('privileged_admin', $current['management_actor_privilege_level'] ?? null);
        self::assertContains('admin_device_management', $current['management_actor_scopes'] ?? []);
        self::assertTrue((bool) ($current['management_actor_can_manage_sessions'] ?? false));
        self::assertTrue((bool) ($current['management_actor_can_manage_trusted_devices'] ?? false));
        self::assertSame('direct_admin', $current['management_actor_session_authorization_mode'] ?? null);
        self::assertSame('direct_admin', $current['management_actor_trusted_device_authorization_mode'] ?? null);
        self::assertSame('self_governed', $current['management_actor_target_relation'] ?? null);
        self::assertSame('governed_current_identity_target', $current['management_actor_target_reason_code'] ?? null);
    }

    public function test_auth_manager_devices_inventory_reads_shared_file_store_across_app_instances(): void
    {
        $sharedRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-auth-shared-' . bin2hex(random_bytes(8));
        $identityConfig = [
            [
                'id' => 48,
                'identifier' => 'shared-device-center@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ];

        $firstApp = new Application($sharedRoot);
        $firstApp->make(ConfigRepository::class)->set('auth.providers.local.identities', $identityConfig);
        $firstApp->make(ConfigRepository::class)->set('auth.session.driver', 'file');
        $firstApp->make(ConfigRepository::class)->set('auth.trusted_devices.driver', 'file');
        $firstRouter = $firstApp->make(Router::class);
        $firstRouter->post('/shared-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'shared-device-center@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $firstRouter->post('/shared-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice('Shared Mac')];
        });

        $secondApp = new Application($sharedRoot);
        $secondApp->make(ConfigRepository::class)->set('auth.providers.local.identities', $identityConfig);
        $secondApp->make(ConfigRepository::class)->set('auth.session.driver', 'file');
        $secondApp->make(ConfigRepository::class)->set('auth.trusted_devices.driver', 'file');
        $secondRouter = $secondApp->make(Router::class);
        $secondRouter->post('/shared-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'shared-device-center@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $secondRouter->get('/shared-inventory', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'trust_state' => $device->trustState,
                    'session_count' => $device->sessionCount,
                    'current' => $device->current,
                    'has_trusted_device' => $device->hasTrustedDevice,
                    'trusted_device_public_id' => $device->trustedDevicePublicId,
                    'management_scope' => $device->managementScope,
                    'management_authority' => $device->managementAuthority,
                    'management_ownership_proof' => $device->managementOwnershipProof,
                    'management_sensitivity' => $device->managementSensitivity,
                    'management_reason_code' => $device->managementReasonCode,
                    'management_actor_governed' => $device->managementActorGoverned,
                    'management_actor_authorized' => $device->managementActorAuthorized,
                    'management_actor_authorization_mode' => $device->managementActorAuthorizationMode,
                    'management_actor_authorization_reason_code' => $device->managementActorAuthorizationReasonCode,
                ], auth()->devices()),
            ];
        });

        $firstKernel = $firstApp->make(HttpKernel::class);
        $firstLogin = $firstKernel->handle(Request::create(
            '/shared-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.80',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);

        $firstEnroll = $firstKernel->handle(Request::create(
            '/shared-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.80',
            ],
        ));
        $firstEnrollPayload = json_decode($firstEnroll->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($firstEnrollPayload['trusted']);

        $secondKernel = $secondApp->make(HttpKernel::class);
        $secondLogin = $secondKernel->handle(Request::create(
            '/shared-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.81',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($secondSessionId);

        $inventoryResponse = $secondKernel->handle(Request::create(
            '/shared-inventory',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.81',
            ],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $devices = $inventoryPayload['devices'] ?? [];

        self::assertCount(2, $devices);

        $current = array_values(array_filter($devices, static fn (array $device): bool => (bool) ($device['current'] ?? false)))[0] ?? null;
        $remoteTrusted = array_values(array_filter($devices, static fn (array $device): bool => ! (bool) ($device['current'] ?? false) && (bool) ($device['has_trusted_device'] ?? false)))[0] ?? null;

        self::assertIsArray($current);
        self::assertIsArray($remoteTrusted);
        self::assertSame(1, $current['session_count'] ?? null);
        self::assertFalse((bool) ($current['has_trusted_device'] ?? true));
        self::assertSame('current', $current['management_scope'] ?? null);
        self::assertSame('session_owner', $current['management_authority'] ?? null);
        self::assertSame('current_session', $current['management_ownership_proof'] ?? null);
        self::assertSame('standard', $current['management_sensitivity'] ?? null);
        self::assertSame('current_device_management', $current['management_reason_code'] ?? null);
        self::assertFalse((bool) ($current['management_actor_governed'] ?? true));
        self::assertFalse((bool) ($current['management_actor_authorized'] ?? true));
        self::assertNull($current['management_actor_authorization_mode'] ?? null);
        self::assertSame('not_administrative_actor', $current['management_actor_authorization_reason_code'] ?? null);

        self::assertSame('trusted', $remoteTrusted['trust_state'] ?? null);
        self::assertSame(1, $remoteTrusted['session_count'] ?? null);
        self::assertTrue((bool) ($remoteTrusted['has_trusted_device'] ?? false));
        self::assertIsString($remoteTrusted['trusted_device_public_id'] ?? null);
        self::assertStringStartsWith('tdv_', $remoteTrusted['trusted_device_public_id'] ?? '');
        self::assertSame('peer', $remoteTrusted['management_scope'] ?? null);
        self::assertSame('identity_owner', $remoteTrusted['management_authority'] ?? null);
        self::assertSame('identity_session', $remoteTrusted['management_ownership_proof'] ?? null);
        self::assertSame('elevated', $remoteTrusted['management_sensitivity'] ?? null);
        self::assertSame('remote_device_management', $remoteTrusted['management_reason_code'] ?? null);
        self::assertFalse((bool) ($remoteTrusted['management_actor_governed'] ?? true));
        self::assertFalse((bool) ($remoteTrusted['management_actor_authorized'] ?? true));
        self::assertNull($remoteTrusted['management_actor_authorization_mode'] ?? null);
        self::assertSame('not_administrative_actor', $remoteTrusted['management_actor_authorization_reason_code'] ?? null);
    }

    public function test_auth_manager_can_revoke_remote_device_from_aggregated_inventory(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 49,
                'identifier' => 'device-revoke@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/device-revoke-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'device-revoke@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/device-revoke-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->get('/device-revoke-inventory', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'current' => $device->current,
                    'has_trusted_device' => $device->hasTrustedDevice,
                    'trusted_device_public_id' => $device->trustedDevicePublicId,
                    'session_count' => $device->sessionCount,
                ], auth()->devices()),
            ];
        });
        $router->post('/device-revoke-remote', function (): array {
            $target = null;

            foreach (auth()->devices() as $device) {
                if (! $device->current) {
                    $target = $device;
                    break;
                }
            }

            return [
                'revoked' => $target !== null ? auth()->revokeDevice($target->deviceReference) : false,
                'remaining_devices' => count(auth()->devices()),
                'target_device_reference' => $target?->deviceReference,
            ];
        });
        $router->get('/device-revoke-protected', function (): array {
            return ['check' => auth()->check()];
        })->middleware('auth');

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create(
            '/device-revoke-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.90',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);

        $firstEnroll = $kernel->handle(Request::create(
            '/device-revoke-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.90',
            ],
        ));
        $firstEnrollPayload = json_decode($firstEnroll->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($firstEnrollPayload['trusted']);

        $secondLogin = $kernel->handle(Request::create(
            '/device-revoke-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.91',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($secondSessionId);

        $inventoryResponse = $kernel->handle(Request::create(
            '/device-revoke-inventory',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.91',
            ],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $devices = $inventoryPayload['devices'] ?? [];
        $remote = array_values(array_filter($devices, static fn (array $device): bool => ! (bool) ($device['current'] ?? false)))[0] ?? null;

        self::assertIsArray($remote);
        self::assertTrue((bool) ($remote['has_trusted_device'] ?? false));
        self::assertIsString($remote['trusted_device_public_id'] ?? null);
        self::assertSame(1, $remote['session_count'] ?? null);

        $revokeResponse = $kernel->handle(Request::create(
            '/device-revoke-remote',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.91',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $revokeResponse->statusCode());
        self::assertTrue($revokePayload['revoked']);
        self::assertSame(1, $revokePayload['remaining_devices'] ?? null);
        self::assertSame($remote['device_reference'] ?? null, $revokePayload['target_device_reference'] ?? null);

        $oldResponse = $kernel->handle(Request::create(
            '/device-revoke-protected',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.90',
            ],
        ));
        $oldPayload = json_decode($oldResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $oldResponse->statusCode());
        self::assertSame('auth.revoked_session', $oldPayload['reason_code'] ?? null);
    }

    public function test_auth_manager_requires_fresh_authentication_to_revoke_remote_device_from_aggregated_inventory(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 50,
                'identifier' => 'device-revoke-fresh@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/device-revoke-fresh-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'device-revoke-fresh@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/device-revoke-fresh-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->get('/device-revoke-fresh-inventory', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'current' => $device->current,
                    'requires_reauthentication' => $device->requiresReauthentication,
                    'management_mode' => $device->managementMode,
                    'management_authority' => $device->managementAuthority,
                    'management_ownership_proof' => $device->managementOwnershipProof,
                    'management_sensitivity' => $device->managementSensitivity,
                    'management_reason_code' => $device->managementReasonCode,
                    'management_actor_governed' => $device->managementActorGoverned,
                    'management_actor_authorized' => $device->managementActorAuthorized,
                    'management_actor_authorization_mode' => $device->managementActorAuthorizationMode,
                    'management_actor_authorization_reason_code' => $device->managementActorAuthorizationReasonCode,
                ], auth()->devices()),
            ];
        });
        $router->post('/device-revoke-fresh-remote', function (): array {
            $target = null;

            foreach (auth()->devices() as $device) {
                if (! $device->current) {
                    $target = $device;
                    break;
                }
            }

            return [
                'revoked' => $target !== null ? auth()->revokeDevice($target->deviceReference) : false,
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create(
            '/device-revoke-fresh-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.92',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);

        $firstEnroll = $kernel->handle(Request::create(
            '/device-revoke-fresh-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.92',
            ],
        ));
        $firstEnrollPayload = json_decode($firstEnroll->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($firstEnrollPayload['trusted']);

        $secondLogin = $kernel->handle(Request::create(
            '/device-revoke-fresh-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.93',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($secondSessionId);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($secondSessionId);

        self::assertInstanceOf(AuthenticationSession::class, $currentSession);

        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;

        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $inventoryResponse = $kernel->handle(Request::create(
            '/device-revoke-fresh-inventory',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.93',
            ],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $devices = $inventoryPayload['devices'] ?? [];
        $remote = array_values(array_filter($devices, static fn (array $device): bool => ! (bool) ($device['current'] ?? false)))[0] ?? null;

        self::assertIsArray($remote);
        self::assertTrue((bool) ($remote['requires_reauthentication'] ?? false));
        self::assertSame('fresh_auth_required', $remote['management_mode'] ?? null);
        self::assertSame('identity_owner', $remote['management_authority'] ?? null);
        self::assertSame('identity_session', $remote['management_ownership_proof'] ?? null);
        self::assertSame('elevated', $remote['management_sensitivity'] ?? null);
        self::assertSame('fresh_authentication_required', $remote['management_reason_code'] ?? null);
        self::assertFalse((bool) ($remote['management_actor_governed'] ?? true));
        self::assertFalse((bool) ($remote['management_actor_authorized'] ?? true));
        self::assertNull($remote['management_actor_authorization_mode'] ?? null);
        self::assertSame('not_administrative_actor', $remote['management_actor_authorization_reason_code'] ?? null);

        $revokeResponse = $kernel->handle(Request::create(
            '/device-revoke-fresh-remote',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.93',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $revokeResponse->statusCode());
        self::assertSame('auth.fresh_authentication_required', $revokePayload['reason_code'] ?? null);
        self::assertSame('device_revocation', $revokePayload['operation'] ?? null);
        self::assertSame('required', $revokeResponse->headers()['X-Auth-Reauthenticate'] ?? null);
        self::assertSame('device_revocation', $revokeResponse->headers()['X-Auth-Operation'] ?? null);
    }

    public function test_auth_manager_allows_governed_admin_to_revoke_remote_device_even_when_freshness_window_expired(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 150,
                'identifier' => 'device-revoke-governed@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => [
                    'security_center_export',
                    'admin_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.management.fresh_auth_window', 300);
        $app->make(ConfigRepository::class)->set('auth.trusted_devices.management.fresh_auth_window', 300);

        $router = $app->make(Router::class);
        $router->post('/device-revoke-governed-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'device-revoke-governed@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/device-revoke-governed-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->get('/device-revoke-governed-inventory', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'current' => $device->current,
                    'requires_reauthentication' => $device->requiresReauthentication,
                    'management_mode' => $device->managementMode,
                    'management_reason_code' => $device->managementReasonCode,
                    'management_actor_governed' => $device->managementActorGoverned,
                    'management_actor_authorized' => $device->managementActorAuthorized,
                    'management_actor_authorization_mode' => $device->managementActorAuthorizationMode,
                    'management_actor_authorization_reason_code' => $device->managementActorAuthorizationReasonCode,
                ], auth()->devices()),
            ];
        });
        $router->post('/device-revoke-governed-remote', function (): array {
            $target = null;

            foreach (auth()->devices() as $device) {
                if (! $device->current) {
                    $target = $device;
                    break;
                }
            }

            return [
                'revoked' => $target !== null ? auth()->revokeDevice($target->deviceReference) : false,
                'remaining_devices' => count(auth()->devices()),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create(
            '/device-revoke-governed-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.120',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($firstSessionId);

        $firstEnroll = $kernel->handle(Request::create(
            '/device-revoke-governed-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.120',
            ],
        ));
        self::assertTrue((json_decode($firstEnroll->content(), true, 512, JSON_THROW_ON_ERROR)['trusted'] ?? false));

        $secondLogin = $kernel->handle(Request::create(
            '/device-revoke-governed-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.121',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($secondSessionId);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($secondSessionId);

        self::assertInstanceOf(AuthenticationSession::class, $currentSession);

        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;

        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $inventoryResponse = $kernel->handle(Request::create(
            '/device-revoke-governed-inventory',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.121',
            ],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $devices = $inventoryPayload['devices'] ?? [];
        $remote = array_values(array_filter($devices, static fn (array $device): bool => ! (bool) ($device['current'] ?? false)))[0] ?? null;

        self::assertIsArray($remote);
        self::assertFalse((bool) ($remote['requires_reauthentication'] ?? true));
        self::assertSame('direct', $remote['management_mode'] ?? null);
        self::assertSame('remote_device_management', $remote['management_reason_code'] ?? null);
        self::assertTrue((bool) ($remote['management_actor_governed'] ?? false));
        self::assertTrue((bool) ($remote['management_actor_authorized'] ?? false));
        self::assertSame('direct_admin', $remote['management_actor_authorization_mode'] ?? null);
        self::assertNull($remote['management_actor_authorization_reason_code'] ?? null);

        $revokeResponse = $kernel->handle(Request::create(
            '/device-revoke-governed-remote',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.121',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $revokeResponse->statusCode());
        self::assertTrue((bool) ($revokePayload['revoked'] ?? false));
        self::assertSame(1, $revokePayload['remaining_devices'] ?? null);
    }

    public function test_auth_manager_can_revoke_current_device_even_when_freshness_window_expired(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 51,
                'identifier' => 'device-revoke-current@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/device-revoke-current-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'device-revoke-current@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/device-revoke-current-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->post('/device-revoke-current', function (): array {
            $current = null;

            foreach (auth()->devices() as $device) {
                if ($device->current) {
                    $current = $device;
                    break;
                }
            }

            return [
                'revoked' => $current !== null ? auth()->revokeDevice($current->deviceReference) : false,
            ];
        });
        $router->get('/device-revoke-current-protected', function (): array {
            return ['check' => auth()->check()];
        })->middleware('auth');

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create(
            '/device-revoke-current-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.94',
            ],
        ));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $enrollResponse = $kernel->handle(Request::create(
            '/device-revoke-current-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.94',
            ],
        ));
        $enrollPayload = json_decode($enrollResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($enrollPayload['trusted']);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($sessionId);

        self::assertInstanceOf(AuthenticationSession::class, $currentSession);

        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;

        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $revokeResponse = $kernel->handle(Request::create(
            '/device-revoke-current',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.94',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $setCookieHeader = $revokeResponse->headers()['Set-Cookie'] ?? null;
        $setCookieValues = is_array($setCookieHeader) ? $setCookieHeader : (is_string($setCookieHeader) ? [$setCookieHeader] : []);
        $trustedCookieCleared = false;

        foreach ($setCookieValues as $value) {
            if (str_contains($value, AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME . '=') && str_contains($value, 'Max-Age=0')) {
                $trustedCookieCleared = true;
                break;
            }
        }

        self::assertSame(200, $revokeResponse->statusCode());
        self::assertTrue($revokePayload['revoked']);
        self::assertSame('cleared', $revokeResponse->headers()['X-Auth-Session'] ?? null);
        self::assertTrue($trustedCookieCleared);

        $afterResponse = $kernel->handle(Request::create(
            '/device-revoke-current-protected',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.94',
            ],
        ));
        $afterPayload = json_decode($afterResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $afterResponse->statusCode());
        self::assertSame('auth.revoked_session', $afterPayload['reason_code'] ?? null);
    }

    public function test_auth_manager_can_revoke_other_devices_in_bulk_from_aggregated_inventory(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 52,
                'identifier' => 'device-bulk-revoke@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/device-bulk-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'device-bulk-revoke@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/device-bulk-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->post('/device-bulk-revoke-others', function (): array {
            return [
                'revoked' => auth()->revokeOtherDevices(),
                'remaining_devices' => count(auth()->devices()),
            ];
        });
        $router->get('/device-bulk-protected', function (): array {
            return ['check' => auth()->check()];
        })->middleware('auth');

        $kernel = $app->make(HttpKernel::class);

        $firstLogin = $kernel->handle(Request::create(
            '/device-bulk-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.95',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($firstSessionId);
        $firstEnroll = $kernel->handle(Request::create(
            '/device-bulk-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.95',
            ],
        ));
        self::assertTrue((json_decode($firstEnroll->content(), true, 512, JSON_THROW_ON_ERROR)['trusted'] ?? false));

        $secondLogin = $kernel->handle(Request::create(
            '/device-bulk-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.96',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($secondSessionId);

        $thirdLogin = $kernel->handle(Request::create(
            '/device-bulk-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.97',
            ],
        ));
        $thirdSessionId = $thirdLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($thirdSessionId);

        $revokeResponse = $kernel->handle(Request::create(
            '/device-bulk-revoke-others',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $thirdSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.97',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $revokeResponse->statusCode());
        self::assertSame(2, $revokePayload['revoked'] ?? null);
        self::assertSame(1, $revokePayload['remaining_devices'] ?? null);

        $firstProtected = $kernel->handle(Request::create(
            '/device-bulk-protected',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.95',
            ],
        ));
        self::assertSame(401, $firstProtected->statusCode());

        $secondProtected = $kernel->handle(Request::create(
            '/device-bulk-protected',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.96',
            ],
        ));
        self::assertSame(401, $secondProtected->statusCode());

        $currentProtected = $kernel->handle(Request::create(
            '/device-bulk-protected',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $thirdSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.97',
            ],
        ));
        self::assertSame(200, $currentProtected->statusCode());
    }

    public function test_auth_manager_requires_fresh_authentication_to_revoke_other_devices_in_bulk(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 53,
                'identifier' => 'device-bulk-fresh@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.management.fresh_auth_window', 300);
        $app->make(ConfigRepository::class)->set('auth.trusted_devices.management.fresh_auth_window', 300);

        $router = $app->make(Router::class);
        $router->post('/device-bulk-fresh-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'device-bulk-fresh@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/device-bulk-fresh-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->post('/device-bulk-fresh-revoke-others', function (): array {
            return ['revoked' => auth()->revokeOtherDevices()];
        });

        $kernel = $app->make(HttpKernel::class);

        $firstLogin = $kernel->handle(Request::create(
            '/device-bulk-fresh-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.98',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($firstSessionId);

        $firstEnroll = $kernel->handle(Request::create(
            '/device-bulk-fresh-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.98',
            ],
        ));
        self::assertTrue((json_decode($firstEnroll->content(), true, 512, JSON_THROW_ON_ERROR)['trusted'] ?? false));

        $secondLogin = $kernel->handle(Request::create(
            '/device-bulk-fresh-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.99',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($secondSessionId);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($secondSessionId);
        self::assertInstanceOf(AuthenticationSession::class, $currentSession);
        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;
        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $revokeResponse = $kernel->handle(Request::create(
            '/device-bulk-fresh-revoke-others',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $secondSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.99',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $revokeResponse->statusCode());
        self::assertSame('auth.fresh_authentication_required', $revokePayload['reason_code'] ?? null);
        self::assertSame('device_revocation_bulk', $revokePayload['operation'] ?? null);
        self::assertSame('required', $revokeResponse->headers()['X-Auth-Reauthenticate'] ?? null);
    }

    public function test_auth_manager_allows_governed_admin_to_revoke_other_devices_in_bulk_even_when_freshness_window_expired(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 151,
                'identifier' => 'device-bulk-governed@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => [
                    'security_center_export',
                    'admin_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.management.fresh_auth_window', 300);
        $app->make(ConfigRepository::class)->set('auth.trusted_devices.management.fresh_auth_window', 300);

        $router = $app->make(Router::class);
        $router->post('/device-bulk-governed-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'device-bulk-governed@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/device-bulk-governed-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->post('/device-bulk-governed-revoke-others', function (): array {
            return [
                'revoked' => auth()->revokeOtherDevices(),
                'remaining_devices' => count(auth()->devices()),
            ];
        });

        $kernel = $app->make(HttpKernel::class);

        $firstLogin = $kernel->handle(Request::create(
            '/device-bulk-governed-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.122',
            ],
        ));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($firstSessionId);

        $firstEnroll = $kernel->handle(Request::create(
            '/device-bulk-governed-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.122',
            ],
        ));
        self::assertTrue((json_decode($firstEnroll->content(), true, 512, JSON_THROW_ON_ERROR)['trusted'] ?? false));

        $secondLogin = $kernel->handle(Request::create(
            '/device-bulk-governed-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.123',
            ],
        ));
        $secondSessionId = $secondLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($secondSessionId);

        $thirdLogin = $kernel->handle(Request::create(
            '/device-bulk-governed-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.124',
            ],
        ));
        $thirdSessionId = $thirdLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($thirdSessionId);

        $repository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $currentSession = $repository->find($thirdSessionId);
        self::assertInstanceOf(AuthenticationSession::class, $currentSession);

        $attributes = $currentSession->attributes;
        $attributes['authentication_fresh_at'] = time() - 601;
        $repository->touch(new AuthenticationSession(
            id: $currentSession->id,
            identity: $currentSession->identity,
            reference: $currentSession->reference,
            method: $currentSession->method,
            issuedAt: $currentSession->issuedAt,
            expiresAt: $currentSession->expiresAt,
            attributes: $attributes,
        ));

        $revokeResponse = $kernel->handle(Request::create(
            '/device-bulk-governed-revoke-others',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $thirdSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.124',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $revokeResponse->statusCode());
        self::assertSame(2, $revokePayload['revoked'] ?? null);
        self::assertSame(1, $revokePayload['remaining_devices'] ?? null);
    }

    public function test_auth_manager_can_revoke_managed_device_for_other_identity_when_actor_is_governed_admin(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 201,
                'identifier' => 'managed-target@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
            [
                'id' => 202,
                'identifier' => 'managed-admin@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => [
                    'security_center_export',
                    'admin_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/managed-target-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'managed-target@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/managed-target-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->get('/managed-target-protected', function (): array {
            return ['check' => auth()->check()];
        })->middleware('auth');
        $router->post('/managed-admin-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'managed-admin@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->get('/managed-admin-devices', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'session_count' => $device->sessionCount,
                    'has_trusted_device' => $device->hasTrustedDevice,
                    'management_actor_governed' => $device->managementActorGoverned,
                    'management_actor_authorized' => $device->managementActorAuthorized,
                    'management_actor_authorization_mode' => $device->managementActorAuthorizationMode,
                    'management_actor_authorization_reason_code' => $device->managementActorAuthorizationReasonCode,
                    'management_actor_authority' => $device->managementActorAuthority,
                    'management_actor_ownership_proof' => $device->managementActorOwnershipProof,
                    'management_actor_claims_source' => $device->managementActorClaimsSource,
                    'management_actor_privilege_level' => $device->managementActorPrivilegeLevel,
                    'management_actor_scopes' => $device->managementActorScopes,
                    'management_actor_can_manage_sessions' => $device->managementActorCanManageSessions,
                    'management_actor_session_authorization_mode' => $device->managementActorSessionAuthorizationMode,
                    'management_actor_session_authorization_reason_code' => $device->managementActorSessionAuthorizationReasonCode,
                    'management_actor_can_manage_trusted_devices' => $device->managementActorCanManageTrustedDevices,
                    'management_actor_trusted_device_authorization_mode' => $device->managementActorTrustedDeviceAuthorizationMode,
                    'management_actor_trusted_device_authorization_reason_code' => $device->managementActorTrustedDeviceAuthorizationReasonCode,
                    'management_actor_target_relation' => $device->managementActorTargetRelation,
                    'management_actor_target_reason_code' => $device->managementActorTargetReasonCode,
                    'management_target_identity' => $device->managementTargetIdentity,
                    'management_target_type' => $device->managementTargetType,
                    'management_target_matches_current_identity' => $device->managementTargetMatchesCurrentIdentity,
                ], auth()->managedDevices('201')),
            ];
        });
        $router->post('/managed-admin-revoke', function (): array {
            $request = RuntimeContext::current()?->request();
            $targetIdentity = is_string($request?->input('identity')) ? trim((string) $request?->input('identity')) : '';
            $deviceReference = is_string($request?->input('device_reference')) ? trim((string) $request?->input('device_reference')) : '';

            return [
                'revoked' => auth()->revokeManagedDevice($targetIdentity, $deviceReference),
            ];
        });

        $kernel = $app->make(HttpKernel::class);

        $targetLogin = $kernel->handle(Request::create(
            '/managed-target-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.130',
            ],
        ));
        $targetSessionId = $targetLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($targetSessionId);

        $targetEnroll = $kernel->handle(Request::create(
            '/managed-target-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $targetSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.130',
            ],
        ));
        self::assertTrue((json_decode($targetEnroll->content(), true, 512, JSON_THROW_ON_ERROR)['trusted'] ?? false));

        $adminLogin = $kernel->handle(Request::create(
            '/managed-admin-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.131',
            ],
        ));
        $adminSessionId = $adminLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($adminSessionId);

        $managedDevicesResponse = $kernel->handle(Request::create(
            '/managed-admin-devices',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $adminSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.131',
            ],
        ));
        $managedDevicesPayload = json_decode($managedDevicesResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $managedDevice = $managedDevicesPayload['devices'][0] ?? null;

        self::assertSame(200, $managedDevicesResponse->statusCode());
        self::assertIsArray($managedDevice);
        self::assertSame(1, $managedDevice['session_count'] ?? null);
        self::assertTrue((bool) ($managedDevice['has_trusted_device'] ?? false));
        self::assertTrue((bool) ($managedDevice['management_actor_governed'] ?? false));
        self::assertTrue((bool) ($managedDevice['management_actor_authorized'] ?? false));
        self::assertSame('direct_admin', $managedDevice['management_actor_authorization_mode'] ?? null);
        self::assertNull($managedDevice['management_actor_authorization_reason_code'] ?? null);
        self::assertSame('administrative_actor', $managedDevice['management_actor_authority'] ?? null);
        self::assertSame('privileged_session', $managedDevice['management_actor_ownership_proof'] ?? null);
        self::assertSame('identity_attributes', $managedDevice['management_actor_claims_source'] ?? null);
        self::assertSame('privileged_admin', $managedDevice['management_actor_privilege_level'] ?? null);
        self::assertContains('admin_device_management', $managedDevice['management_actor_scopes'] ?? []);
        self::assertTrue((bool) ($managedDevice['management_actor_can_manage_sessions'] ?? false));
        self::assertTrue((bool) ($managedDevice['management_actor_can_manage_trusted_devices'] ?? false));
        self::assertSame('direct_admin', $managedDevice['management_actor_session_authorization_mode'] ?? null);
        self::assertSame('direct_admin', $managedDevice['management_actor_trusted_device_authorization_mode'] ?? null);
        self::assertSame('direct_administrative_target', $managedDevice['management_actor_target_relation'] ?? null);
        self::assertSame('direct_administrative_target', $managedDevice['management_actor_target_reason_code'] ?? null);
        self::assertSame('201', $managedDevice['management_target_identity'] ?? null);
        self::assertSame('user', $managedDevice['management_target_type'] ?? null);
        self::assertFalse((bool) ($managedDevice['management_target_matches_current_identity'] ?? true));

        $sessionRepository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $targetSession = $sessionRepository->find($targetSessionId);
        self::assertInstanceOf(AuthenticationSession::class, $targetSession);
        $targetDeviceReference = $managedDevice['device_reference'] ?? null;
        self::assertIsString($targetDeviceReference);

        $revokeResponse = $kernel->handle(Request::create(
            '/managed-admin-revoke',
            'POST',
            [
                'identity' => '201',
                'device_reference' => $targetDeviceReference,
            ],
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $adminSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.131',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $revokeResponse->statusCode());
        self::assertTrue((bool) ($revokePayload['revoked'] ?? false));
        self::assertNull($sessionRepository->find($targetSessionId));

        $trustedDeviceRepository = $app->make(TrustedDeviceRepositoryInterface::class);
        self::assertCount(0, array_filter(
            $trustedDeviceRepository->all(),
            static fn ($device): bool => $device->reference->identifier->value === '201'
                && $device->deviceReference === $targetDeviceReference,
        ));

        $targetProtected = $kernel->handle(Request::create(
            '/managed-target-protected',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $targetSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.130',
            ],
        ));
        self::assertSame(401, $targetProtected->statusCode());
    }

    public function test_auth_manager_managed_devices_exposes_delegated_actor_target_relation(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 241,
                'identifier' => 'delegated-target@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
            [
                'id' => 242,
                'identifier' => 'delegated-actor@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'delegated_session',
                'auth_management_scopes' => ['admin_device_management'],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'delegated_support',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/delegated-target-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'delegated-target@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/delegated-actor-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'delegated-actor@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->get('/delegated-actor-devices', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'management_actor_authorization_mode' => $device->managementActorAuthorizationMode,
                    'management_actor_target_relation' => $device->managementActorTargetRelation,
                    'management_actor_target_reason_code' => $device->managementActorTargetReasonCode,
                    'management_actor_target_scope_relation' => $device->managementActorTargetScopeRelation,
                    'management_actor_target_scope_reason_code' => $device->managementActorTargetScopeReasonCode,
                    'management_target_identity' => $device->managementTargetIdentity,
                    'management_target_type' => $device->managementTargetType,
                ], auth()->managedDevices('241')),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $targetLogin = $kernel->handle(Request::create(
            '/delegated-target-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.160',
            ],
        ));
        self::assertIsString($targetLogin->headers()['X-Auth-Session'] ?? null);

        $actorLogin = $kernel->handle(Request::create(
            '/delegated-actor-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.161',
            ],
        ));
        $actorSessionId = $actorLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($actorSessionId);

        $managedDevicesResponse = $kernel->handle(Request::create(
            '/delegated-actor-devices',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $actorSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.161',
            ],
        ));
        $payload = json_decode($managedDevicesResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $device = $payload['devices'][0] ?? null;

        self::assertSame(200, $managedDevicesResponse->statusCode());
        self::assertIsArray($device);
        self::assertSame('delegated_admin', $device['management_actor_authorization_mode'] ?? null);
        self::assertSame('delegated_administrative_target', $device['management_actor_target_relation'] ?? null);
        self::assertSame('delegated_administrative_target', $device['management_actor_target_reason_code'] ?? null);
        self::assertSame('delegated_admin_full_scope_target', $device['management_actor_target_scope_relation'] ?? null);
        self::assertSame('delegated_admin_full_scope_target', $device['management_actor_target_scope_reason_code'] ?? null);
        self::assertSame('241', $device['management_target_identity'] ?? null);
        self::assertSame('user', $device['management_target_type'] ?? null);
    }

    public function test_auth_manager_supports_delegated_session_only_scope_for_managed_devices(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 251,
                'identifier' => 'delegated-session-target@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
            [
                'id' => 252,
                'identifier' => 'delegated-session-actor@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'delegated_session',
                'auth_management_scopes' => ['admin_session_management'],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'delegated_support',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/delegated-session-target-login', fn (): array => ['ok' => auth()->attempt([
            'identifier' => 'delegated-session-target@example.com',
            'password' => 'secret-123',
            'second_factor' => '654321',
        ])]);
        $router->post('/delegated-session-target-enroll', fn (): array => ['trusted' => auth()->trustCurrentDevice()]);
        $router->post('/delegated-session-actor-login', fn (): array => ['ok' => auth()->attempt([
            'identifier' => 'delegated-session-actor@example.com',
            'password' => 'secret-123',
            'second_factor' => '654321',
        ])]);
        $router->get('/delegated-session-actor-devices', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'management_actor_can_manage_sessions' => $device->managementActorCanManageSessions,
                    'management_actor_session_authorization_mode' => $device->managementActorSessionAuthorizationMode,
                    'management_actor_session_authorization_reason_code' => $device->managementActorSessionAuthorizationReasonCode,
                    'management_actor_can_manage_trusted_devices' => $device->managementActorCanManageTrustedDevices,
                    'management_actor_trusted_device_authorization_mode' => $device->managementActorTrustedDeviceAuthorizationMode,
                    'management_actor_trusted_device_authorization_reason_code' => $device->managementActorTrustedDeviceAuthorizationReasonCode,
                    'management_actor_target_scope_relation' => $device->managementActorTargetScopeRelation,
                    'management_actor_target_scope_reason_code' => $device->managementActorTargetScopeReasonCode,
                ], auth()->managedDevices('251')),
            ];
        });
        $router->post('/delegated-session-actor-revoke', function (): array {
            $request = RuntimeContext::current()?->request();

            return [
                'revoked' => auth()->revokeManagedDevice(
                    trim((string) $request?->input('identity')),
                    trim((string) $request?->input('device_reference')),
                    null,
                    trim((string) ($request?->input('scope') ?? 'all')),
                ),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $targetLogin = $kernel->handle(Request::create('/delegated-session-target-login', 'POST', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
            'REMOTE_ADDR' => '203.0.113.170',
        ]));
        $targetSessionId = $targetLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($targetSessionId);

        $targetEnroll = $kernel->handle(Request::create(
            '/delegated-session-target-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $targetSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.170',
            ],
        ));
        self::assertTrue((bool) (json_decode($targetEnroll->content(), true, 512, JSON_THROW_ON_ERROR)['trusted'] ?? false));

        $actorLogin = $kernel->handle(Request::create('/delegated-session-actor-login', 'POST', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
            'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
            'REMOTE_ADDR' => '203.0.113.171',
        ]));
        $actorSessionId = $actorLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($actorSessionId);

        $managedDevicesResponse = $kernel->handle(Request::create(
            '/delegated-session-actor-devices',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $actorSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.171',
            ],
        ));
        $payload = json_decode($managedDevicesResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $device = $payload['devices'][0] ?? null;
        self::assertIsArray($device);

        $deviceReference = $device['device_reference'] ?? null;
        self::assertIsString($deviceReference);
        self::assertTrue((bool) ($device['management_actor_can_manage_sessions'] ?? false));
        self::assertSame('delegated_admin', $device['management_actor_session_authorization_mode'] ?? null);
        self::assertFalse((bool) ($device['management_actor_can_manage_trusted_devices'] ?? true));
        self::assertSame('missing_admin_trusted_device_management_scope', $device['management_actor_trusted_device_authorization_reason_code'] ?? null);
        self::assertSame('delegated_admin_sessions_scope_target', $device['management_actor_target_scope_relation'] ?? null);
        self::assertSame('delegated_admin_sessions_scope_target', $device['management_actor_target_scope_reason_code'] ?? null);

        $revokeSessions = $kernel->handle(Request::create(
            '/delegated-session-actor-revoke',
            'POST',
            ['identity' => '251', 'device_reference' => $deviceReference, 'scope' => 'sessions'],
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $actorSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.171',
            ],
        ));
        $revokeSessionsPayload = json_decode($revokeSessions->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($revokeSessionsPayload['revoked'] ?? false));

        $revokeTrustedDevices = $kernel->handle(Request::create(
            '/delegated-session-actor-revoke',
            'POST',
            ['identity' => '251', 'device_reference' => $deviceReference, 'scope' => 'trusted-devices'],
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $actorSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.171',
            ],
        ));
        $revokeTrustedDevicesPayload = json_decode($revokeTrustedDevices->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) ($revokeTrustedDevicesPayload['revoked'] ?? true));

        $sessionRepository = $app->make(AuthenticationSessionRepositoryInterface::class);
        self::assertNull($sessionRepository->find($targetSessionId));

        $trustedDeviceRepository = $app->make(TrustedDeviceRepositoryInterface::class);
        self::assertCount(1, array_filter(
            $trustedDeviceRepository->all(),
            static fn ($trustedDevice): bool => $trustedDevice->reference->identifier->value === '251'
                && $trustedDevice->deviceReference === $deviceReference,
        ));
    }

    public function test_auth_manager_supports_delegated_trusted_device_only_scope_for_managed_devices(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 261,
                'identifier' => 'delegated-trusted-target@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
            [
                'id' => 262,
                'identifier' => 'delegated-trusted-actor@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'delegated_session',
                'auth_management_scopes' => ['admin_trusted_device_management'],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'delegated_support',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/delegated-trusted-target-login', fn (): array => ['ok' => auth()->attempt([
            'identifier' => 'delegated-trusted-target@example.com',
            'password' => 'secret-123',
            'second_factor' => '654321',
        ])]);
        $router->post('/delegated-trusted-target-enroll', fn (): array => ['trusted' => auth()->trustCurrentDevice()]);
        $router->post('/delegated-trusted-actor-login', fn (): array => ['ok' => auth()->attempt([
            'identifier' => 'delegated-trusted-actor@example.com',
            'password' => 'secret-123',
            'second_factor' => '654321',
        ])]);
        $router->get('/delegated-trusted-actor-devices', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'management_actor_can_manage_sessions' => $device->managementActorCanManageSessions,
                    'management_actor_session_authorization_reason_code' => $device->managementActorSessionAuthorizationReasonCode,
                    'management_actor_can_manage_trusted_devices' => $device->managementActorCanManageTrustedDevices,
                    'management_actor_trusted_device_authorization_mode' => $device->managementActorTrustedDeviceAuthorizationMode,
                    'management_actor_target_scope_relation' => $device->managementActorTargetScopeRelation,
                    'management_actor_target_scope_reason_code' => $device->managementActorTargetScopeReasonCode,
                ], auth()->managedDevices('261')),
            ];
        });
        $router->post('/delegated-trusted-actor-revoke', function (): array {
            $request = RuntimeContext::current()?->request();

            return [
                'revoked' => auth()->revokeManagedDevice(
                    trim((string) $request?->input('identity')),
                    trim((string) $request?->input('device_reference')),
                    null,
                    trim((string) ($request?->input('scope') ?? 'all')),
                ),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $targetLogin = $kernel->handle(Request::create('/delegated-trusted-target-login', 'POST', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
            'REMOTE_ADDR' => '203.0.113.172',
        ]));
        $targetSessionId = $targetLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($targetSessionId);

        $targetEnroll = $kernel->handle(Request::create(
            '/delegated-trusted-target-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $targetSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.172',
            ],
        ));
        self::assertTrue((bool) (json_decode($targetEnroll->content(), true, 512, JSON_THROW_ON_ERROR)['trusted'] ?? false));

        $actorLogin = $kernel->handle(Request::create('/delegated-trusted-actor-login', 'POST', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
            'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
            'REMOTE_ADDR' => '203.0.113.173',
        ]));
        $actorSessionId = $actorLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($actorSessionId);

        $managedDevicesResponse = $kernel->handle(Request::create(
            '/delegated-trusted-actor-devices',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $actorSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.173',
            ],
        ));
        $payload = json_decode($managedDevicesResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $device = $payload['devices'][0] ?? null;
        self::assertIsArray($device);

        $deviceReference = $device['device_reference'] ?? null;
        self::assertIsString($deviceReference);
        self::assertFalse((bool) ($device['management_actor_can_manage_sessions'] ?? true));
        self::assertSame('missing_admin_session_management_scope', $device['management_actor_session_authorization_reason_code'] ?? null);
        self::assertTrue((bool) ($device['management_actor_can_manage_trusted_devices'] ?? false));
        self::assertSame('delegated_admin', $device['management_actor_trusted_device_authorization_mode'] ?? null);
        self::assertSame('delegated_admin_trusted_devices_scope_target', $device['management_actor_target_scope_relation'] ?? null);
        self::assertSame('delegated_admin_trusted_devices_scope_target', $device['management_actor_target_scope_reason_code'] ?? null);

        $revokeSessions = $kernel->handle(Request::create(
            '/delegated-trusted-actor-revoke',
            'POST',
            ['identity' => '261', 'device_reference' => $deviceReference, 'scope' => 'sessions'],
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $actorSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.173',
            ],
        ));
        $revokeSessionsPayload = json_decode($revokeSessions->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) ($revokeSessionsPayload['revoked'] ?? true));

        $revokeTrustedDevices = $kernel->handle(Request::create(
            '/delegated-trusted-actor-revoke',
            'POST',
            ['identity' => '261', 'device_reference' => $deviceReference, 'scope' => 'trusted-devices'],
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $actorSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.173',
            ],
        ));
        $revokeTrustedDevicesPayload = json_decode($revokeTrustedDevices->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($revokeTrustedDevicesPayload['revoked'] ?? false));

        $sessionRepository = $app->make(AuthenticationSessionRepositoryInterface::class);
        self::assertInstanceOf(AuthenticationSession::class, $sessionRepository->find($targetSessionId));

        $trustedDeviceRepository = $app->make(TrustedDeviceRepositoryInterface::class);
        self::assertCount(0, array_filter(
            $trustedDeviceRepository->all(),
            static fn ($trustedDevice): bool => $trustedDevice->reference->identifier->value === '261'
                && $trustedDevice->deviceReference === $deviceReference,
        ));
    }

    public function test_auth_manager_can_revoke_only_managed_sessions_for_other_identity(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 221,
                'identifier' => 'managed-sessions-target@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
            [
                'id' => 222,
                'identifier' => 'managed-sessions-admin@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => ['security_center_export', 'admin_device_management'],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/managed-sessions-target-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'managed-sessions-target@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/managed-sessions-target-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->get('/managed-sessions-target-protected', function (): array {
            return ['check' => auth()->check()];
        })->middleware('auth');
        $router->post('/managed-sessions-admin-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'managed-sessions-admin@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->get('/managed-sessions-admin-devices', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'session_count' => $device->sessionCount,
                    'has_trusted_device' => $device->hasTrustedDevice,
                ], auth()->managedDevices('221')),
            ];
        });
        $router->post('/managed-sessions-admin-revoke', function (): array {
            $request = RuntimeContext::current()?->request();
            $targetIdentity = is_string($request?->input('identity')) ? trim((string) $request?->input('identity')) : '';
            $deviceReference = is_string($request?->input('device_reference')) ? trim((string) $request?->input('device_reference')) : '';
            $scope = is_string($request?->input('scope')) ? trim((string) $request?->input('scope')) : 'all';

            return [
                'revoked' => auth()->revokeManagedDevice($targetIdentity, $deviceReference, null, $scope),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $targetLogin = $kernel->handle(Request::create(
            '/managed-sessions-target-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.140',
            ],
        ));
        $targetSessionId = $targetLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($targetSessionId);

        $targetEnroll = $kernel->handle(Request::create(
            '/managed-sessions-target-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $targetSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.140',
            ],
        ));
        self::assertTrue((json_decode($targetEnroll->content(), true, 512, JSON_THROW_ON_ERROR)['trusted'] ?? false));

        $adminLogin = $kernel->handle(Request::create(
            '/managed-sessions-admin-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.141',
            ],
        ));
        $adminSessionId = $adminLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($adminSessionId);

        $managedDevicesResponse = $kernel->handle(Request::create(
            '/managed-sessions-admin-devices',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $adminSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.141',
            ],
        ));
        $managedDevicesPayload = json_decode($managedDevicesResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $managedDevice = $managedDevicesPayload['devices'][0] ?? null;
        self::assertIsArray($managedDevice);

        $targetDeviceReference = $managedDevice['device_reference'] ?? null;
        self::assertIsString($targetDeviceReference);

        $revokeResponse = $kernel->handle(Request::create(
            '/managed-sessions-admin-revoke',
            'POST',
            [
                'identity' => '221',
                'device_reference' => $targetDeviceReference,
                'scope' => 'sessions',
            ],
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $adminSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.141',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $revokeResponse->statusCode());
        self::assertTrue((bool) ($revokePayload['revoked'] ?? false));

        $sessionRepository = $app->make(AuthenticationSessionRepositoryInterface::class);
        self::assertNull($sessionRepository->find($targetSessionId));

        $trustedDeviceRepository = $app->make(TrustedDeviceRepositoryInterface::class);
        self::assertCount(1, array_filter(
            $trustedDeviceRepository->all(),
            static fn ($device): bool => $device->reference->identifier->value === '221'
                && $device->deviceReference === $targetDeviceReference,
        ));

        $targetProtected = $kernel->handle(Request::create(
            '/managed-sessions-target-protected',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $targetSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.140',
            ],
        ));
        self::assertSame(401, $targetProtected->statusCode());
    }

    public function test_auth_manager_can_revoke_only_managed_trusted_devices_for_other_identity(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 231,
                'identifier' => 'managed-trusted-target@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
            [
                'id' => 232,
                'identifier' => 'managed-trusted-admin@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => ['security_center_export', 'admin_device_management'],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/managed-trusted-target-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'managed-trusted-target@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->post('/managed-trusted-target-enroll', function (): array {
            return ['trusted' => auth()->trustCurrentDevice()];
        });
        $router->get('/managed-trusted-target-protected', function (): array {
            return ['check' => auth()->check()];
        })->middleware('auth');
        $router->post('/managed-trusted-admin-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'managed-trusted-admin@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->get('/managed-trusted-admin-devices', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                    'session_count' => $device->sessionCount,
                    'has_trusted_device' => $device->hasTrustedDevice,
                ], auth()->managedDevices('231')),
            ];
        });
        $router->post('/managed-trusted-admin-revoke', function (): array {
            $request = RuntimeContext::current()?->request();
            $targetIdentity = is_string($request?->input('identity')) ? trim((string) $request?->input('identity')) : '';
            $deviceReference = is_string($request?->input('device_reference')) ? trim((string) $request?->input('device_reference')) : '';
            $scope = is_string($request?->input('scope')) ? trim((string) $request?->input('scope')) : 'all';

            return [
                'revoked' => auth()->revokeManagedDevice($targetIdentity, $deviceReference, null, $scope),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $targetLogin = $kernel->handle(Request::create(
            '/managed-trusted-target-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.150',
            ],
        ));
        $targetSessionId = $targetLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($targetSessionId);

        $targetEnroll = $kernel->handle(Request::create(
            '/managed-trusted-target-enroll',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $targetSessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.150',
            ],
        ));
        self::assertTrue((json_decode($targetEnroll->content(), true, 512, JSON_THROW_ON_ERROR)['trusted'] ?? false));

        $adminLogin = $kernel->handle(Request::create(
            '/managed-trusted-admin-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.151',
            ],
        ));
        $adminSessionId = $adminLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($adminSessionId);

        $managedDevicesResponse = $kernel->handle(Request::create(
            '/managed-trusted-admin-devices',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $adminSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.151',
            ],
        ));
        $managedDevicesPayload = json_decode($managedDevicesResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $managedDevice = $managedDevicesPayload['devices'][0] ?? null;
        self::assertIsArray($managedDevice);

        $targetDeviceReference = $managedDevice['device_reference'] ?? null;
        self::assertIsString($targetDeviceReference);

        $revokeResponse = $kernel->handle(Request::create(
            '/managed-trusted-admin-revoke',
            'POST',
            [
                'identity' => '231',
                'device_reference' => $targetDeviceReference,
                'scope' => 'trusted-devices',
            ],
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $adminSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.151',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $revokeResponse->statusCode());
        self::assertTrue((bool) ($revokePayload['revoked'] ?? false));

        $sessionRepository = $app->make(AuthenticationSessionRepositoryInterface::class);
        self::assertInstanceOf(AuthenticationSession::class, $sessionRepository->find($targetSessionId));

        $trustedDeviceRepository = $app->make(TrustedDeviceRepositoryInterface::class);
        self::assertCount(0, array_filter(
            $trustedDeviceRepository->all(),
            static fn ($device): bool => $device->reference->identifier->value === '231'
                && $device->deviceReference === $targetDeviceReference,
        ));

        $targetProtected = $kernel->handle(Request::create(
            '/managed-trusted-target-protected',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $targetSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.150',
            ],
        ));
        self::assertSame(200, $targetProtected->statusCode());
    }

    public function test_auth_manager_rejects_managed_device_revocation_for_non_governed_actor(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 211,
                'identifier' => 'managed-target-reject@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
            [
                'id' => 212,
                'identifier' => 'managed-plain-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/managed-reject-target-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'managed-target-reject@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->get('/managed-reject-target-protected', function (): array {
            return ['check' => auth()->check()];
        })->middleware('auth');
        $router->post('/managed-reject-actor-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'managed-plain-user@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->get('/managed-reject-actor-devices', function (): array {
            return [
                'devices' => array_map(static fn ($device): array => [
                    'device_reference' => $device->deviceReference,
                ], auth()->managedDevices('211')),
            ];
        });
        $router->post('/managed-reject-actor-revoke', function (): array {
            $request = RuntimeContext::current()?->request();
            $targetIdentity = is_string($request?->input('identity')) ? trim((string) $request?->input('identity')) : '';
            $deviceReference = is_string($request?->input('device_reference')) ? trim((string) $request?->input('device_reference')) : '';

            return [
                'revoked' => auth()->revokeManagedDevice($targetIdentity, $deviceReference),
            ];
        });

        $kernel = $app->make(HttpKernel::class);

        $targetLogin = $kernel->handle(Request::create(
            '/managed-reject-target-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.132',
            ],
        ));
        $targetSessionId = $targetLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($targetSessionId);

        $actorLogin = $kernel->handle(Request::create(
            '/managed-reject-actor-login',
            'POST',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.133',
            ],
        ));
        $actorSessionId = $actorLogin->headers()['X-Auth-Session'] ?? null;
        self::assertIsString($actorSessionId);

        $managedDevicesResponse = $kernel->handle(Request::create(
            '/managed-reject-actor-devices',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $actorSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.133',
            ],
        ));
        $managedDevicesPayload = json_decode($managedDevicesResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $managedDevicesResponse->statusCode());
        self::assertSame([], $managedDevicesPayload['devices'] ?? null);

        $sessionRepository = $app->make(AuthenticationSessionRepositoryInterface::class);
        $targetSession = $sessionRepository->find($targetSessionId);
        self::assertInstanceOf(AuthenticationSession::class, $targetSession);
        $targetDeviceReference = $targetSession->attributes['session_device_reference'] ?? null;
        self::assertIsString($targetDeviceReference);

        $revokeResponse = $kernel->handle(Request::create(
            '/managed-reject-actor-revoke',
            'POST',
            [
                'identity' => '211',
                'device_reference' => $targetDeviceReference,
            ],
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $actorSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0',
                'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9',
                'REMOTE_ADDR' => '203.0.113.133',
            ],
        ));
        $revokePayload = json_decode($revokeResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $revokeResponse->statusCode());
        self::assertFalse((bool) ($revokePayload['revoked'] ?? true));
        self::assertInstanceOf(AuthenticationSession::class, $sessionRepository->find($targetSessionId));

        $targetProtected = $kernel->handle(Request::create(
            '/managed-reject-target-protected',
            'GET',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $targetSessionId],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.8',
                'REMOTE_ADDR' => '203.0.113.132',
            ],
        ));
        self::assertSame(200, $targetProtected->statusCode());
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

    public function test_attempt_or_fail_requires_second_factor_when_identity_demands_it(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 62,
                'identifier' => 'mfa-required@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'mfa_required' => true,
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/auth-attempt-mfa-required', function (): void {
            auth()->attemptOrFail([
                'identifier' => 'mfa-required@example.com',
                'password' => 'secret-123',
            ]);
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/auth-attempt-mfa-required',
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->statusCode());
        self::assertSame('Second factor verification is required.', $payload['message'] ?? null);
        self::assertSame('auth.second_factor_required', $response->headers()['X-Volt-Error-Code'] ?? null);
    }

    public function test_step_up_or_fail_requires_an_authenticated_session(): void
    {
        $app = new Application(sys_get_temp_dir());

        $router = $app->make(Router::class);
        $router->post('/step-up-requires-auth', function (): void {
            auth()->stepUpOrFail([
                'second_factor' => '654321',
            ]);
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/step-up-requires-auth',
            'POST',
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->statusCode());
        self::assertSame('An authenticated session is required to perform step-up.', $payload['message'] ?? null);
        self::assertSame('auth.step_up_requires_authentication', $response->headers()['X-Volt-Error-Code'] ?? null);
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

    public function test_auth_middleware_returns_stale_session_denial_for_expired_session_credentials(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 131,
                'identifier' => 'stale-session-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.lifetime', 0);

        $router = $app->make(Router::class);
        $router->get('/stale-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'stale-session-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/stale-protected', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        })->middleware('auth');

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create('/stale-login'));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);
        self::assertNotSame('', trim($sessionId));

        $response = $kernel->handle(Request::create(
            '/stale-protected',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->statusCode());
        self::assertSame('The authentication session is stale or invalid.', $payload['message'] ?? null);
        self::assertSame('auth.stale_session', $payload['reason_code'] ?? null);
        self::assertSame('auth.stale_session', $response->headers()['X-Volt-Error-Code'] ?? null);
        self::assertStringContainsString('Max-Age=0', $response->headers()['Set-Cookie'] ?? '');
        self::assertArrayNotHasKey('WWW-Authenticate', $response->headers());
    }

    public function test_auth_middleware_returns_revoked_session_denial_for_invalidated_session_credentials(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 132,
                'identifier' => 'revoked-session-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);
        $app->make(ConfigRepository::class)->set('auth.session.revoke_others_on_login', true);

        $router = $app->make(Router::class);
        $router->get('/revoked-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'revoked-session-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/revoked-protected', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        })->middleware('auth');

        $kernel = $app->make(HttpKernel::class);
        $firstLogin = $kernel->handle(Request::create('/revoked-login'));
        $firstSessionId = $firstLogin->headers()['X-Auth-Session'] ?? null;
        $secondLogin = $kernel->handle(Request::create('/revoked-login'));

        self::assertIsString($firstSessionId);

        $response = $kernel->handle(Request::create(
            '/revoked-protected',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $firstSessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->statusCode());
        self::assertSame('The authentication session has been revoked.', $payload['message'] ?? null);
        self::assertSame('auth.revoked_session', $payload['reason_code'] ?? null);
        self::assertSame('auth.revoked_session', $response->headers()['X-Volt-Error-Code'] ?? null);
        self::assertStringContainsString('Max-Age=0', $response->headers()['Set-Cookie'] ?? '');
        self::assertArrayNotHasKey('WWW-Authenticate', $response->headers());
    }

    public function test_mfa_middleware_alias_requires_step_up_for_single_factor_sessions(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 135,
                'identifier' => 'mfa-alias-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/mfa-alias-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'mfa-alias-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->post('/mfa-alias-step-up', function (): array {
            return ['ok' => auth()->stepUp([
                'second_factor' => '654321',
            ])];
        });
        $router->get('/mfa-alias-protected', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
                'strength' => auth()->context()?->authenticationStrength()->name,
            ];
        })->middleware('mfa');

        $kernel = $app->make(HttpKernel::class);
        $guestResponse = $kernel->handle(Request::create(
            '/mfa-alias-protected',
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $guestPayload = json_decode($guestResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $guestResponse->statusCode());
        self::assertSame('Authentication required.', $guestPayload['message'] ?? null);
        self::assertSame('auth.required', $guestResponse->headers()['X-Volt-Error-Code'] ?? null);

        $loginResponse = $kernel->handle(Request::create('/mfa-alias-login'));
        $singleFactorSessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($singleFactorSessionId);

        $insufficientResponse = $kernel->handle(Request::create(
            '/mfa-alias-protected',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $singleFactorSessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $insufficientPayload = json_decode($insufficientResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $insufficientResponse->statusCode());
        self::assertSame('Step-up authentication is required for this resource.', $insufficientPayload['message'] ?? null);
        self::assertSame('auth.step_up_required', $insufficientPayload['reason_code'] ?? null);
        self::assertSame('MultiFactor', $insufficientPayload['required_strength_name'] ?? null);
        self::assertSame('Password', $insufficientPayload['current_strength_name'] ?? null);
        self::assertSame('required', $insufficientResponse->headers()['X-Auth-Step-Up'] ?? null);
        self::assertSame('MultiFactor', $insufficientResponse->headers()['X-Auth-Required-Strength'] ?? null);
        self::assertArrayNotHasKey('WWW-Authenticate', $insufficientResponse->headers());

        $stepUpResponse = $kernel->handle(Request::create(
            '/mfa-alias-step-up',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $singleFactorSessionId],
        ));
        $stepUpPayload = json_decode($stepUpResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $elevatedSessionId = $stepUpResponse->headers()['X-Auth-Session'] ?? null;

        self::assertTrue($stepUpPayload['ok']);
        self::assertIsString($elevatedSessionId);

        $protectedResponse = $kernel->handle(Request::create(
            '/mfa-alias-protected',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $elevatedSessionId],
        ));
        $protectedPayload = json_decode($protectedResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $protectedResponse->statusCode());
        self::assertTrue($protectedPayload['check']);
        self::assertSame('135', (string) $protectedPayload['id']);
        self::assertSame('MultiFactor', $protectedPayload['strength']);
    }

    public function test_auth_middleware_honors_route_mfa_metadata_as_step_up_entry_point(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 136,
                'identifier' => 'mfa-meta-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/mfa-meta-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'mfa-meta-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/mfa-meta-protected', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        })->middleware('auth')->mfa();

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create('/mfa-meta-login'));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $response = $kernel->handle(Request::create(
            '/mfa-meta-protected',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->statusCode());
        self::assertSame('auth.step_up_required', $payload['reason_code'] ?? null);
        self::assertSame('required', $response->headers()['X-Auth-Step-Up'] ?? null);
        self::assertArrayNotHasKey('WWW-Authenticate', $response->headers());
    }

    public function test_auth_middleware_can_require_a_generic_higher_authentication_strength_from_route_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 141,
                'identifier' => 'strength-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/strength-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'strength-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->get('/strength-protected', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
            ];
        })
            ->middleware('auth')
            ->auth([
                'minimum_strength' => AuthenticationStrength::HardwareBacked->name,
                'minimum_strength_value' => AuthenticationStrength::HardwareBacked->value,
            ]);

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create('/strength-login'));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $response = $kernel->handle(Request::create(
            '/strength-protected',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: ['HTTP_ACCEPT' => 'application/json'],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->statusCode());
        self::assertSame('Authentication strength is insufficient for this resource.', $payload['message'] ?? null);
        self::assertSame('authentication_strength_insufficient', $payload['reason_code'] ?? null);
        self::assertSame(AuthenticationStrength::HardwareBacked->value, $payload['challenge']['required_strength_value'] ?? null);
        self::assertSame(AuthenticationStrength::Password->value, $payload['challenge']['current_strength_value'] ?? null);
        self::assertSame(
            'controller.security.authentication.authentication_strength_insufficient',
            $response->headers()['X-Volt-Error-Code'] ?? null,
        );
        self::assertStringContainsString('required_strength_value="40"', $response->headers()['WWW-Authenticate'] ?? '');
        self::assertStringContainsString('current_strength_value="10"', $response->headers()['WWW-Authenticate'] ?? '');
        self::assertStringContainsString('error="insufficient_strength"', $response->headers()['WWW-Authenticate'] ?? '');
    }

    public function test_multi_factor_session_can_satisfy_multi_factor_route_requirement_after_recovery(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 142,
                'identifier' => 'strength-mfa-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/strength-mfa-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'strength-mfa-user@example.com',
                'password' => 'secret-123',
                'second_factor' => '654321',
            ])];
        });
        $router->get('/strength-mfa-protected', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
                'strength' => auth()->context()?->authenticationStrength()->name,
                'assurance_profile' => auth()->context()?->authenticationAssuranceProfile(),
                'amr' => auth()->context()?->attribute('amr'),
            ];
        })
            ->middleware('auth')
            ->auth([
                'minimum_strength' => AuthenticationStrength::MultiFactor->name,
                'minimum_strength_value' => AuthenticationStrength::MultiFactor->value,
            ]);

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create('/strength-mfa-login'));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $response = $kernel->handle(Request::create(
            '/strength-mfa-protected',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode());
        self::assertTrue($payload['check']);
        self::assertSame('142', (string) $payload['id']);
        self::assertSame('MultiFactor', $payload['strength']);
        self::assertSame('multi_factor', $payload['assurance_profile']);
        self::assertSame(['pwd', 'mfa'], $payload['amr']);
    }

    public function test_step_up_session_can_satisfy_multi_factor_route_requirement_after_recovery(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('auth.providers.local.identities', [
            [
                'id' => 143,
                'identifier' => 'step-up-strength-user@example.com',
                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                'mfa_code' => '654321',
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->get('/step-up-strength-login', function (): array {
            return ['ok' => auth()->attempt([
                'identifier' => 'step-up-strength-user@example.com',
                'password' => 'secret-123',
            ])];
        });
        $router->post('/step-up-strength-elevate', function (): array {
            return ['ok' => auth()->stepUp([
                'second_factor' => '654321',
            ])];
        });
        $router->get('/step-up-strength-protected', function (): array {
            return [
                'check' => auth()->check(),
                'id' => auth()->id(),
                'strength' => auth()->context()?->authenticationStrength()->name,
                'assurance_profile' => auth()->context()?->authenticationAssuranceProfile(),
                'amr' => auth()->context()?->attribute('amr'),
            ];
        })
            ->middleware('auth')
            ->auth([
                'minimum_strength' => AuthenticationStrength::MultiFactor->name,
                'minimum_strength_value' => AuthenticationStrength::MultiFactor->value,
            ]);

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create('/step-up-strength-login'));
        $initialSessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($initialSessionId);

        $stepUpResponse = $kernel->handle(Request::create(
            '/step-up-strength-elevate',
            'POST',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $initialSessionId],
        ));
        $stepUpPayload = json_decode($stepUpResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $elevatedSessionId = $stepUpResponse->headers()['X-Auth-Session'] ?? null;

        self::assertTrue($stepUpPayload['ok']);
        self::assertIsString($elevatedSessionId);
        self::assertNotSame($initialSessionId, $elevatedSessionId);

        $response = $kernel->handle(Request::create(
            '/step-up-strength-protected',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $elevatedSessionId],
        ));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode());
        self::assertTrue($payload['check']);
        self::assertSame('143', (string) $payload['id']);
        self::assertSame('MultiFactor', $payload['strength']);
        self::assertSame('multi_factor', $payload['assurance_profile']);
        self::assertSame(['pwd', 'mfa'], $payload['amr']);
    }

    /**
     * @param array<int, string>|string|null $header
     */
    private function extractCookieValue(array|string|null $header, string $cookieName): ?string
    {
        $values = is_array($header) ? $header : (is_string($header) ? [$header] : []);

        foreach ($values as $value) {
            if (! str_starts_with($value, $cookieName . '=')) {
                continue;
            }

            $pair = explode(';', $value, 2)[0] ?? '';
            $encoded = explode('=', $pair, 2)[1] ?? null;

            if (! is_string($encoded) || trim($encoded) === '') {
                return null;
            }

            return rawurldecode(trim($encoded));
        }

        return null;
    }
}
