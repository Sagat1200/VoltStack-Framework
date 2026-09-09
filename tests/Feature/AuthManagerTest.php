<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
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
                    'ip_prefix' => $session->ipPrefix,
                    'last_activity_at' => $session->lastActivityAt,
                    'issued_at' => $session->issuedAt,
                ], auth()->sessions()),
            ];
        });

        $kernel = $app->make(HttpKernel::class);
        $loginResponse = $kernel->handle(Request::create(
            '/metadata-login',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/128.0',
                'REMOTE_ADDR' => '203.0.113.42',
            ],
        ));
        $sessionId = $loginResponse->headers()['X-Auth-Session'] ?? null;

        self::assertIsString($sessionId);

        $inventoryResponse = $kernel->handle(Request::create(
            '/metadata-sessions',
            cookies: [AuthenticationHttpState::SESSION_COOKIE_NAME => $sessionId],
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/128.0',
                'REMOTE_ADDR' => '203.0.113.42',
            ],
        ));
        $inventoryPayload = json_decode($inventoryResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $session = $inventoryPayload['sessions'][0] ?? null;

        self::assertIsArray($session);
        self::assertStringStartsWith('sess_pub_', $session['public_id'] ?? '');
        self::assertSame('Chrome', $session['client_family'] ?? null);
        self::assertSame('203.0.113.x', $session['ip_prefix'] ?? null);
        self::assertSame('Chrome from 203.0.113.x', $session['label'] ?? null);
        self::assertIsInt($session['issued_at'] ?? null);
        self::assertIsInt($session['last_activity_at'] ?? null);
        self::assertGreaterThanOrEqual($session['issued_at'], $session['last_activity_at']);
        self::assertNotSame('Mozilla/5.0 Chrome/128.0', $session['client_family'] ?? null);
        self::assertNotSame('203.0.113.42', $session['ip_prefix'] ?? null);
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
}
