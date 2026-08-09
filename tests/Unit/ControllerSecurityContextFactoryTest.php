<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
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
}
