<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Exceptions\AuthenticationException;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Sessions\AuthenticationSessionSummary;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Controllers\Security\Context\ControllerSecurityContextFactory;
use Quantum\Controllers\Security\Context\Principal;
use Quantum\Controllers\Security\Context\PrincipalType;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Context\SecurityAttributes;
use Quantum\Controllers\Security\Context\TenantIdentity;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionCache;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\ControllerTarget;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Http\Request;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;

final class ControllerSecurityContextFactoryTest extends TestCase
{
    private function buildExecCtx(Request $r): ControllerExecutionContext
    {
        $match = new RouteMatch(new Route(RouteDefinition::make(['GET'], '/t', 'A@b')), [], 'GET');
        return new ControllerExecutionContext($r, $match);
    }

    public function test_factory_anonymous_by_default(): void
    {
        $factory = new ControllerSecurityContextFactory();
        $req = Request::create('/t');
        $exec = $this->buildExecCtx($req);
        $ctx = $factory->create($req, $exec);

        self::assertFalse($ctx->principal->authenticated());
        self::assertSame(PrincipalType::Anonymous, $ctx->principal->type());
        self::assertSame(AuthenticationStrength::Anonymous, $ctx->authenticationStrength);
        self::assertNull($ctx->tenant);
    }

    public function test_factory_accepts_bearer_jwt_like_token_sets_auth_strength_token(): void
    {
        $factory = new ControllerSecurityContextFactory();
        $payload = [
            'sub' => 'u-42',
            'type' => 'user',
            'roles' => ['user', 'viewer'],
            'permissions' => ['dashboard:read'],
            'email' => 'a@b.co',
        ];
        $header = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none']) ?: ''), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode(json_encode($payload) ?: ''), '+/', '-_'), '=');
        $token = $header . '.' . $body . '.sig';
        $server = ['Authorization' => 'Bearer ' . $token];
        $req = Request::create('/t', 'GET', [], [], [], [], [], $server);
        $ctx = $factory->create($req, $this->buildExecCtx($req));

        self::assertTrue($ctx->principal->authenticated());
        self::assertSame(PrincipalType::User, $ctx->principal->type());
        self::assertSame('u-42', $ctx->principal->id());
        self::assertSame(AuthenticationStrength::Token, $ctx->authenticationStrength, sprintf('Token strength failure, roles=%s', var_export($ctx->principal->claims()['roles'] ?? null, true)));
        $claims = $ctx->principal->claims();
        self::assertContains('viewer', (array)($claims['roles'] ?? []));
        self::assertContains('dashboard:read', (array)($claims['permissions'] ?? []));
        self::assertSame('a@b.co', $claims['email'] ?? null);
    }

    public function test_factory_mfa_amr_gives_authentication_strength_multi_factor(): void
    {
        $factory = new ControllerSecurityContextFactory();
        $payload = [
            'sub' => 'admin-mfa',
            'type' => 'user',
            'roles' => ['admin'],
            'permissions' => ['admin.panel'],
            'amr' => ['mfa', 'pwd'],
        ];
        $header = rtrim(strtr(base64_encode(json_encode(['typ'=>'JWT','alg'=>'none'])?:''),'+/','-_'),'=');
        $body = rtrim(strtr(base64_encode(json_encode($payload)?:''),'+/','-_'),'=');
        $server = ['Authorization' => 'Bearer '.$header.'.'.$body.'.x'];
        $req = Request::create('/t', 'GET', [], [], [], [], [], $server);
        $ctx = $factory->create($req, $this->buildExecCtx($req));

        self::assertSame(AuthenticationStrength::MultiFactor, $ctx->authenticationStrength);
        self::assertTrue($ctx->principal->authenticated());
    }

    public function test_factory_tenant_header_creates_verified_tenant_identity(): void
    {
        $factory = new ControllerSecurityContextFactory();
        $server = ['X-Tenant-Id' => 'acme-corp'];
        $req = Request::create('/t', 'GET', [], [], [], [], [], $server);
        $ctx = $factory->create($req, $this->buildExecCtx($req));

        self::assertNotNull($ctx->tenant);
        self::assertSame('acme-corp', $ctx->tenant->id);
        self::assertTrue($ctx->tenant->verified);
        self::assertSame('http_header:x-tenant-id', $ctx->tenant->source);
        $attrs = $ctx->attributes->attributes;
        self::assertSame('acme-corp', $attrs['tenant_id'] ?? null);
    }

    public function test_factory_scopes_header_stored_in_attributes(): void
    {
        $factory = new ControllerSecurityContextFactory();
        $server = ['X-Scopes' => 'read, write, profile:me'];
        $req = Request::create('/t', 'GET', [], [], [], [], [], $server);
        $ctx = $factory->create($req, $this->buildExecCtx($req));
        $attrs = $ctx->attributes->attributes;
        $scopes = (array)($attrs['scopes'] ?? []);
        self::assertSame(['read', 'write', 'profile:me'], $scopes);
    }

    public function test_factory_bearer_raw_non_jwt_also_sets_authenticated_token_strength(): void
    {
        $factory = new ControllerSecurityContextFactory();
        $server = ['Authorization' => 'Bearer abc123notjwt'];
        $req = Request::create('/t', 'GET', [], [], [], [], [], $server);
        $ctx = $factory->create($req, $this->buildExecCtx($req));
        self::assertTrue($ctx->principal->authenticated());
        self::assertSame(AuthenticationStrength::Token, $ctx->authenticationStrength);
        self::assertSame(PrincipalType::ApiClient, $ctx->principal->type());
    }

    public function test_factory_can_derive_principal_from_quantum_auth_context_when_no_bearer_token_exists(): void
    {
        $auth = new class implements AuthenticationManagerInterface {
            public function attempt(array $credentials): bool
            {
                return false;
            }

            public function attemptOrFail(array $credentials): void
            {
                throw new AuthenticationException('Not implemented.');
            }

            public function stepUp(array $credentials): bool
            {
                return false;
            }

            public function stepUpOrFail(array $credentials): void
            {
                throw new AuthenticationException('Not implemented.');
            }

            public function login(mixed $user): void {}

            public function user(): mixed
            {
                return null;
            }

            public function setUser(mixed $user): void {}

            public function check(): bool
            {
                return true;
            }

            public function guest(): bool
            {
                return false;
            }

            public function id(): mixed
            {
                return 'session-admin@example.com';
            }

            public function context(): ?AuthenticationContext
            {
                $identity = new GenericIdentity(
                    identifier: new IdentityIdentifier('session-admin@example.com'),
                    type: 'user',
                    attributes: [
                        'name' => 'Session Admin',
                        'roles' => ['admin'],
                        'permissions' => ['admin.panel'],
                    ],
                );

                return new AuthenticationContext(
                    identity: $identity,
                    reference: new IdentityReference($identity->identifier(), $identity->type()),
                    requestId: 'req-session-auth',
                    method: 'password',
                    attributes: [
                        'authentication_strength' => AuthenticationStrength::MultiFactor->name,
                        'authentication_assurance_profile' => 'multi_factor',
                        'authentication_fresh_at' => time(),
                        'session_public_id' => 'sess_pub_controller_ctx',
                        'session_device_reference' => 'devref_controller_ctx',
                        'session_device_trust_state' => 'trusted',
                        'trusted_device_public_id' => 'tdv_controller_ctx',
                        'trusted_device_credential_present' => true,
                        'amr' => ['pwd', 'mfa'],
                    ],
                );
            }

            public function recoveryFailureReason(): ?string
            {
                return null;
            }

            public function currentSession(): ?AuthenticationSessionSummary
            {
                return null;
            }

            public function sessions(): array
            {
                return [];
            }

            public function trustedDevices(): array
            {
                return [];
            }

            public function devices(): array
            {
                return [];
            }

            public function trustCurrentDevice(?string $label = null): bool
            {
                return false;
            }

            public function forgetTrustedDevice(string $publicId): bool
            {
                return false;
            }

            public function revokeDevice(string $deviceReference): bool
            {
                return false;
            }

            public function revokeOtherDevices(): int
            {
                return 0;
            }

            public function revokeSession(string $publicId): bool
            {
                return false;
            }

            public function revokeOtherSessions(): int
            {
                return 0;
            }

            public function logout(): void {}
        };

        $factory = new ControllerSecurityContextFactory(auth: $auth);
        $req = Request::create('/t', 'GET');
        $ctx = $factory->create($req, $this->buildExecCtx($req));
        $claims = $ctx->principal->claims();
        $attributes = $ctx->attributes->attributes;

        self::assertTrue($ctx->principal->authenticated());
        self::assertSame(PrincipalType::User, $ctx->principal->type());
        self::assertSame('session-admin@example.com', $ctx->principal->id());
        self::assertSame(AuthenticationStrength::MultiFactor, $ctx->authenticationStrength);
        self::assertSame(['admin'], $claims['roles'] ?? []);
        self::assertSame(['admin.panel'], $claims['permissions'] ?? []);
        self::assertSame('Session Admin', $claims['name'] ?? null);
        self::assertSame('session_owner', $claims['management_authority'] ?? null);
        self::assertSame('current_session', $claims['management_ownership_proof'] ?? null);
        self::assertSame([
            'current_session_management',
            'current_device_management',
            'identity_session_management',
            'identity_device_management',
        ], $claims['management_scopes'] ?? []);
        self::assertSame('self_service_defaults', $claims['management_claims_source'] ?? null);
        self::assertSame('self_service', $claims['management_privilege_level'] ?? null);
        self::assertSame('multi_factor', $attributes['auth_assurance_profile'] ?? null);
        self::assertSame('sess_pub_controller_ctx', $attributes['auth_session_public_id'] ?? null);
        self::assertSame('devref_controller_ctx', $attributes['auth_device_reference'] ?? null);
        self::assertSame('trusted', $attributes['auth_device_trust_state'] ?? null);
        self::assertSame('tdv_controller_ctx', $attributes['auth_trusted_device_public_id'] ?? null);
        self::assertTrue((bool) ($attributes['auth_trusted_device_credential_present'] ?? false));
        self::assertSame('session_owner', $attributes['auth_management_authority'] ?? null);
        self::assertSame('current_session', $attributes['auth_management_ownership_proof'] ?? null);
        self::assertSame([
            'current_session_management',
            'current_device_management',
            'identity_session_management',
            'identity_device_management',
        ], $attributes['auth_management_scopes'] ?? []);
        self::assertSame('self_service_defaults', $attributes['auth_management_claims_source'] ?? null);
        self::assertSame('self_service', $attributes['auth_management_privilege_level'] ?? null);
        self::assertSame(['pwd', 'mfa'], $attributes['amr'] ?? []);
    }

    public function test_factory_projects_privileged_management_claims_from_identity_attributes(): void
    {
        $auth = new class implements AuthenticationManagerInterface {
            public function attempt(array $credentials): bool
            {
                return false;
            }

            public function attemptOrFail(array $credentials): void
            {
                throw new AuthenticationException('Not implemented.');
            }

            public function stepUp(array $credentials): bool
            {
                return false;
            }

            public function stepUpOrFail(array $credentials): void
            {
                throw new AuthenticationException('Not implemented.');
            }

            public function login(mixed $user): void {}
            public function user(): mixed { return null; }
            public function setUser(mixed $user): void {}
            public function check(): bool { return true; }
            public function guest(): bool { return false; }
            public function id(): mixed { return 'ops-admin@example.com'; }

            public function context(): ?AuthenticationContext
            {
                $identity = new GenericIdentity(
                    identifier: new IdentityIdentifier('ops-admin@example.com'),
                    type: 'user',
                    attributes: [
                        'name' => 'Ops Admin',
                        'roles' => ['admin'],
                        'permissions' => ['admin.panel', 'security-center.export'],
                        'auth_management_authority' => 'administrative_actor',
                        'auth_management_ownership_proof' => 'privileged_session',
                        'auth_management_scopes' => ['security_center_export', 'admin_device_management'],
                        'auth_management_claims_source' => 'identity_attributes',
                        'auth_management_privilege_level' => 'privileged_admin',
                    ],
                );

                return new AuthenticationContext(
                    identity: $identity,
                    reference: new IdentityReference($identity->identifier(), $identity->type()),
                    requestId: 'req-ops-admin',
                    method: 'password',
                    attributes: [
                        'authentication_strength' => AuthenticationStrength::MultiFactor->name,
                        'authentication_assurance_profile' => 'multi_factor',
                        'amr' => ['pwd', 'mfa'],
                    ],
                );
            }

            public function recoveryFailureReason(): ?string { return null; }
            public function currentSession(): ?AuthenticationSessionSummary { return null; }
            public function sessions(): array { return []; }
            public function trustedDevices(): array { return []; }
            public function devices(): array { return []; }
            public function trustCurrentDevice(?string $label = null): bool { return false; }
            public function forgetTrustedDevice(string $publicId): bool { return false; }
            public function revokeDevice(string $deviceReference): bool { return false; }
            public function revokeOtherDevices(): int { return 0; }
            public function revokeSession(string $publicId): bool { return false; }
            public function revokeOtherSessions(): int { return 0; }
            public function logout(): void {}
        };

        $factory = new ControllerSecurityContextFactory(auth: $auth);
        $req = Request::create('/t', 'GET');
        $ctx = $factory->create($req, $this->buildExecCtx($req));
        $claims = $ctx->principal->claims();
        $attributes = $ctx->attributes->attributes;

        self::assertSame('administrative_actor', $claims['management_authority'] ?? null);
        self::assertSame('privileged_session', $claims['management_ownership_proof'] ?? null);
        self::assertSame(['security_center_export', 'admin_device_management'], $claims['management_scopes'] ?? []);
        self::assertSame('identity_attributes', $claims['management_claims_source'] ?? null);
        self::assertSame('privileged_admin', $claims['management_privilege_level'] ?? null);
        self::assertSame('administrative_actor', $attributes['auth_management_authority'] ?? null);
        self::assertSame('identity_attributes', $attributes['auth_management_claims_source'] ?? null);
        self::assertSame('privileged_admin', $attributes['auth_management_privilege_level'] ?? null);
    }
}
