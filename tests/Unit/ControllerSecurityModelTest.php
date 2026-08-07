<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Controllers\Security\Context\ControllerSecurityContextFactory;
use Quantum\Controllers\Security\Context\Principal;
use Quantum\Controllers\Security\Context\PrincipalType;
use Quantum\Controllers\Security\Context\TenantIdentity;
use Quantum\Controllers\Security\Context\SecurityAttributes;
use Quantum\Controllers\Security\Budget\ControllerSecurityBudget;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionCache;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityDecisionKey;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\ControllerTarget;
use Quantum\Controllers\Security\ControllerTargetType;
use Quantum\Controllers\Security\Policy\ControllerSecurityDecisionEngine;
use Quantum\Controllers\Security\Policy\ControllerSecurityPolicy;
use Quantum\Controllers\Security\Policy\ControllerSecurityPolicyRegistry;
use Quantum\Controllers\Security\Engine\ControllerSecurityManager;
use Quantum\Controllers\Security\Exceptions\AuthenticationRequiredException;
use Quantum\Controllers\Security\Exceptions\AuthorizationDeniedException;
use Quantum\Controllers\Security\Exceptions\SecurityInfrastructureFailureException;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Http\Request;
use Quantum\Routing\Dispatching\ControllerDispatcher;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;

final class ControllerSecurityModelTest extends TestCase
{
    public function test_principal_type_enum_covers_expected_cases(): void
    {
        self::assertSame('anonymous', PrincipalType::Anonymous->value);
        self::assertSame('user', PrincipalType::User->value);
        self::assertSame('service', PrincipalType::Service->value);
        self::assertSame('api_client', PrincipalType::ApiClient->value);
        self::assertSame('system', PrincipalType::System->value);
        self::assertSame('impersonated_user', PrincipalType::ImpersonatedUser->value);
    }

    public function test_authentication_strength_is_ordered_for_comparison(): void
    {
        self::assertLessThan(AuthenticationStrength::Password->value, AuthenticationStrength::Anonymous->value);
        self::assertLessThan(AuthenticationStrength::Token->value, AuthenticationStrength::Password->value);
        self::assertLessThan(AuthenticationStrength::MultiFactor->value, AuthenticationStrength::Token->value);
        self::assertLessThan(AuthenticationStrength::HardwareBacked->value, AuthenticationStrength::MultiFactor->value);
    }

    public function test_principal_anonymous_factory_sets_expected_properties(): void
    {
        $p = Principal::anonymous();
        self::assertSame(PrincipalType::Anonymous, $p->type());
        self::assertFalse($p->authenticated());
        self::assertNotEmpty($p->id());
        self::assertSame([], $p->claims());
    }

    public function test_security_context_factory_always_creates_anonymous_context(): void
    {
        $factory = new ControllerSecurityContextFactory(32);
        $request = Request::create('/test', 'GET');
        $match = new RouteMatch(new Route(RouteDefinition::make(['GET'], '/test', 'Fake@index')), [], 'GET');
        $execCtx = new ControllerExecutionContext($request, $match);
        $ctx = $factory->create($request, $execCtx);

        self::assertInstanceOf(ControllerSecurityContext::class, $ctx);
        self::assertTrue($ctx->isAnonymous());
        self::assertNull($ctx->tenant);
        self::assertFalse($ctx->hasTenant());
        self::assertSame(AuthenticationStrength::Anonymous, $ctx->authenticationStrength);
        self::assertNotEmpty($ctx->executionId);
        self::assertSame(32, $ctx->budget->maxPolicyEvaluations);
        self::assertInstanceOf(SecurityDecisionCache::class, $ctx->decisions);
    }

    public function test_security_context_with_verified_tenant_reports_has_tenant(): void
    {
        $budget = new ControllerSecurityBudget(8);
        $principal = new Principal('u-42', PrincipalType::User, true, ['role' => 'admin']);
        $tenant = new TenantIdentity('t-1', 'host', true);
        $ctx = new ControllerSecurityContext(
            principal: $principal,
            tenant: $tenant,
            authenticationStrength: AuthenticationStrength::MultiFactor,
            attributes: new SecurityAttributes(['geo' => 'ar']),
            decisions: new SecurityDecisionCache(4),
            executionId: 'exec-xyz',
            budget: $budget,
        );
        self::assertFalse($ctx->isAnonymous());
        self::assertTrue($ctx->hasTenant());
        self::assertSame('t-1', $tenant->id);
        self::assertTrue($ctx->attributes->has('geo'));
        self::assertSame('ar', $ctx->attributes->get('geo'));
    }

    public function test_controller_target_from_definition_handles_class_at_method(): void
    {
        $def = new ControllerDefinition(\Vendor\Package\MyController::class . '@update');
        $target = ControllerTarget::fromDefinition($def);

        self::assertSame(ControllerTargetType::ControllerMethod, $target->type);
        self::assertSame(\Vendor\Package\MyController::class, $target->identifier);
        self::assertSame('update', $target->method);
        self::assertTrue($target->exposed);
        self::assertSame(\Vendor\Package\MyController::class . '::update', $target->signature);
    }

    public function test_controller_target_from_definition_handles_invokable(): void
    {
        $def = new ControllerDefinition(\Vendor\Package\InvokableStub::class);
        $target = ControllerTarget::fromDefinition($def, exposed: false, source: 'config');

        self::assertSame(ControllerTargetType::InvokableController, $target->type);
        self::assertSame('__invoke', $target->method);
        self::assertFalse($target->exposed);
        self::assertSame('config', $target->source);
    }

    public function test_security_decision_static_constructors_reflect_effect(): void
    {
        $allow = SecurityDecision::allow('p1', 'owner');
        self::assertTrue($allow->isAllow());
        self::assertFalse($allow->isDeny());
        self::assertSame(SecurityDecisionEffect::Allow, $allow->effect);

        $deny = SecurityDecision::deny('p1', 'forbidden');
        self::assertFalse($deny->isAllow());
        self::assertTrue($deny->isDeny());

        $challenge = SecurityDecision::challenge('p1', 'mfa');
        self::assertTrue($challenge->isDeny());

        $abstain = SecurityDecision::abstain('p1');
        self::assertFalse($abstain->isAllow());
        self::assertFalse($abstain->isDeny());
    }

    public function test_decision_cache_stores_and_retrieves_by_key_and_evicts_oldest_when_full(): void
    {
        $cache = new SecurityDecisionCache(maxItems: 2);
        $k1 = new SecurityDecisionKey('u1', 't1', 'p', 'read', 'r1');
        $k2 = new SecurityDecisionKey('u1', 't1', 'p', 'write', 'r1');
        $k3 = new SecurityDecisionKey('u1', 't1', 'p', 'delete', 'r1');

        $cache->put($k1, SecurityDecision::allow('p'));
        $cache->put($k2, SecurityDecision::deny('p'));
        self::assertNotNull($cache->get($k1));
        self::assertSame(2, $cache->count());

        $cache->put($k3, SecurityDecision::allow('p'));
        self::assertNull($cache->get($k1));
        self::assertNotNull($cache->get($k2));
        self::assertNotNull($cache->get($k3));

        self::assertSame(2, $cache->clear());
        self::assertSame(0, $cache->count());
    }

    public function test_policy_registry_rejects_duplicate_registration_after_freeze(): void
    {
        $reg = new ControllerSecurityPolicyRegistry();
        $p1 = new class extends ControllerSecurityPolicy {
            public function id(): string { return 'p.one'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision { return $this->abstain(); }
        };
        $reg->register($p1);
        self::assertFalse($reg->frozen());
        self::assertSame(1, $reg->count());

        $reg->freeze();
        self::assertTrue($reg->frozen());

        $this->expectException(SecurityInfrastructureFailureException::class);
        $p2 = new class extends ControllerSecurityPolicy {
            public function id(): string { return 'p.two'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision { return $this->abstain(); }
        };
        $reg->register($p2);
    }

    public function test_policy_registry_resolve_missing_throws_infrastructure(): void
    {
        $reg = new ControllerSecurityPolicyRegistry();
        $this->expectException(SecurityInfrastructureFailureException::class);
        $reg->resolve('nonexistent');
    }

    private function buildAnonymousContext(): ControllerSecurityContext
    {
        $factory = new ControllerSecurityContextFactory(32);
        $req = Request::create('/t', 'GET');
        $match = new RouteMatch(new Route(RouteDefinition::make(['GET'], '/t', 'A@b')), [], 'GET');
        $execCtx = new ControllerExecutionContext($req, $match);

        return $factory->create($req, $execCtx);
    }

    public function test_decision_engine_deny_by_default_when_no_policies_and_no_requirements_nor_permissions(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'defaults' => ['deny_by_default' => true, 'fail_closed' => true],
            'authorization' => ['max_policy_evaluations' => 64, 'abstain_as_deny' => true],
            'policies' => [],
        ]);

        $engine = new ControllerSecurityDecisionEngine(new ControllerSecurityPolicyRegistry(), $app);
        $ctx = $this->buildAnonymousContext();
        $target = ControllerTarget::fromDefinition(new ControllerDefinition('X@y'));
        $req = new SecurityEvaluationRequest($ctx, $target, 'y', 'resource:1', metadata: []);

        $d = $engine->decide($req);
        self::assertTrue($d->isDeny(), 'Expected deny, got: ' . $d->effect->name . ' / ' . $d->reasonCode);
        self::assertSame('no_policy_registered_deny_by_default', $d->reasonCode);
    }

    public function test_decision_engine_allow_by_default_when_configured_fail_open(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['max_policy_evaluations' => 64, 'abstain_as_deny' => false],
            'policies' => [],
        ]);
        $engine = new ControllerSecurityDecisionEngine(new ControllerSecurityPolicyRegistry(), $app);
        $ctx = $this->buildAnonymousContext();
        $target = ControllerTarget::fromDefinition(new ControllerDefinition('X@y'));
        $req = new SecurityEvaluationRequest($ctx, $target, 'y', 'resource:1', metadata: []);

        $d = $engine->decide($req);
        self::assertTrue($d->isAllow(), 'Expected allow, got: ' . $d->effect->name . ' / ' . $d->reasonCode);
        self::assertSame('no_explicit_decision_allow_by_default', $d->reasonCode);
    }

    public function test_decision_engine_short_circuits_on_first_explicit_deny_policy(): void
    {
        $allowPolicy = new class extends ControllerSecurityPolicy {
            public function id(): string { return 'always.allow'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision { return $this->allow('open'); }
        };
        $denyPolicy = new class extends ControllerSecurityPolicy {
            public function id(): string { return 'always.deny'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision { return $this->deny('locked'); }
        };
        $reg = new ControllerSecurityPolicyRegistry();
        $reg->register($allowPolicy);
        $reg->register($denyPolicy);

        $app = new Application(sys_get_temp_dir());
        $engine = new ControllerSecurityDecisionEngine($reg, $app);
        $ctx = $this->buildAnonymousContext();
        $target = ControllerTarget::fromDefinition(new ControllerDefinition('X@y'));
        $req = new SecurityEvaluationRequest($ctx, $target, 'y', 'r', ['policies' => ['always.allow', 'always.deny']]);

        $d = $engine->decide($req);
        self::assertTrue($d->isDeny());
        self::assertSame('always.deny', $d->policyId);
        self::assertSame('locked', $d->reasonCode);
    }

    public function test_decision_engine_challenge_when_authentication_required_but_anonymous(): void
    {
        $app = new Application(sys_get_temp_dir());
        $engine = new ControllerSecurityDecisionEngine(new ControllerSecurityPolicyRegistry(), $app);
        $ctx = $this->buildAnonymousContext();
        $target = ControllerTarget::fromDefinition(new ControllerDefinition('X@y'));
        $req = new SecurityEvaluationRequest($ctx, $target, 'y', 'r', [
            'authentication_required' => true,
        ]);

        $d = $engine->decide($req);
        self::assertTrue($d->isDeny());
        self::assertSame(SecurityDecisionEffect::Challenge, $d->effect);
        self::assertSame('security.authentication_required', $d->policyId);
    }

    public function test_decision_engine_deny_when_tenant_required_but_missing(): void
    {
        $app = new Application(sys_get_temp_dir());
        $engine = new ControllerSecurityDecisionEngine(new ControllerSecurityPolicyRegistry(), $app);
        $ctx = $this->buildAnonymousContext();
        $target = ControllerTarget::fromDefinition(new ControllerDefinition('X@y'));
        $req = new SecurityEvaluationRequest($ctx, $target, 'y', 'r', [
            'tenant_required' => true,
        ]);

        $d = $engine->decide($req);
        self::assertTrue($d->isDeny());
        self::assertSame('security.tenant_required', $d->policyId);
    }

    public function test_manager_assert_authorized_throws_authentication_required_for_challenge(): void
    {
        $app = new Application(sys_get_temp_dir());
        $reg = new ControllerSecurityPolicyRegistry();
        $engine = new ControllerSecurityDecisionEngine($reg, $app);
        $factory = new ControllerSecurityContextFactory(32);
        $manager = new ControllerSecurityManager($factory, $engine);

        $ctx = $this->buildAnonymousContext();
        $target = ControllerTarget::fromDefinition(new ControllerDefinition('X@y'));
        $evalReq = new SecurityEvaluationRequest($ctx, $target, 'y', 'r', [
            'authentication_required' => true,
        ]);

        $this->expectException(AuthenticationRequiredException::class);
        $manager->assertAuthorized($evalReq);
    }

    public function test_manager_assert_authorized_throws_authorization_denied_for_deny(): void
    {
        $app = new Application(sys_get_temp_dir());
        $denyPolicy = new class extends ControllerSecurityPolicy {
            public function id(): string { return 'deny.policy'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision { return $this->deny('not_owner'); }
        };
        $reg = new ControllerSecurityPolicyRegistry();
        $reg->register($denyPolicy);
        $engine = new ControllerSecurityDecisionEngine($reg, $app);
        $factory = new ControllerSecurityContextFactory(32);
        $manager = new ControllerSecurityManager($factory, $engine);

        $ctx = $this->buildAnonymousContext();
        $target = ControllerTarget::fromDefinition(new ControllerDefinition('X@y'));
        $evalReq = new SecurityEvaluationRequest($ctx, $target, 'y', 'r', ['policies' => ['deny.policy']]);

        try {
            $manager->assertAuthorized($evalReq);
            self::fail('Expected AuthorizationDeniedException not thrown');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame('not_owner', $e->reasonCode);
            self::assertSame('deny.policy', $e->safeContext['policy_id'] ?? '');
        }
    }

    public function test_manager_finalize_clears_decision_cache(): void
    {
        $app = new Application(sys_get_temp_dir());
        $reg = new ControllerSecurityPolicyRegistry();
        $engine = new ControllerSecurityDecisionEngine($reg, $app);
        $factory = new ControllerSecurityContextFactory(32);
        $manager = new ControllerSecurityManager($factory, $engine);

        $ctx = $this->buildAnonymousContext();
        $key = new SecurityDecisionKey('u', 't', 'p', 'a', 'r');
        $ctx->decisions->put($key, SecurityDecision::allow('p'));
        self::assertGreaterThan(0, $ctx->decisions->count());

        $manager->finalize($ctx);
        self::assertSame(0, $ctx->decisions->count());
    }

    public function test_engine_container_bindings_resolve_and_hook_passes_security_disabled(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => false,
            'defaults' => ['deny_by_default' => true],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/security-disabled', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/security-disabled', SecurityOpenStubController::class . '@ping')),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);

        self::assertSame(200, $response->statusCode());
        self::assertSame('pong', $response->content());
    }

    public function test_engine_dispatcher_hook_denies_when_security_enabled_and_deny_by_default(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'defaults' => ['deny_by_default' => true, 'fail_closed' => true, 'authentication_required' => false, 'tenant_required' => false],
            'authorization' => ['cache_per_execution' => true, 'max_policy_evaluations' => 64, 'abstain_as_deny' => true],
            'policies' => [],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/protected', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/protected', SecurityOpenStubController::class . '@ping')),
            [],
            'GET',
        );

        $this->expectException(AuthorizationDeniedException::class);
        $dispatcher->dispatch($match, $request);
    }

    public function test_engine_dispatcher_hook_allows_when_policy_allows(): void
    {
        $allowPolicy = new class extends ControllerSecurityPolicy {
            public function id(): string { return 'test.everyone.allowed'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision { return $this->allow('open_barrier'); }
        };

        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'defaults' => ['deny_by_default' => true, 'fail_closed' => true],
            'authorization' => ['max_policy_evaluations' => 64, 'abstain_as_deny' => true],
            'policies' => [$allowPolicy],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $request = Request::create('/allowed', 'GET');
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/allowed', SecurityOpenStubController::class . '@ping')),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, $request);
        self::assertSame(200, $response->statusCode());
        self::assertSame('pong', $response->content());
    }
}

final class SecurityOpenStubController
{
    public function ping(): string
    {
        return 'pong';
    }
}
