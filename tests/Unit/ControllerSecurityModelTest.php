<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Security\Attributes\AuthenticationRequired;
use Quantum\Controllers\Security\Attributes\Expose;
use Quantum\Controllers\Security\Attributes\Policies;
use Quantum\Controllers\Security\Attributes\Permissions;
use Quantum\Controllers\Security\Attributes\PolicyClass;
use Quantum\Controllers\Security\Attributes\TenantRequired;
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
use Quantum\Controllers\Security\Exceptions\ControllerExposureViolationException;
use Quantum\Controllers\Security\Exceptions\SecurityInfrastructureFailureException;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Http\Request;
use Quantum\Routing\CompiledRoute;
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
        self::assertNull($target->exposed, 'Exposed defaults to null (unset tri-state)');
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

    public function test_explicit_exposure_disabled_bypasses_allowlist_even_when_missing(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => false,
                'allowlist' => [],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false],
            'policies' => [],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/t', SecurityOpenStubController::class . '@ping')),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, Request::create('/t', 'GET'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('pong', $response->content());
    }

    public function test_explicit_exposure_enabled_throws_when_controller_missing_from_allowlist_and_no_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => true,
                'allowlist' => [],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false],
            'policies' => [],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/t', SecurityOpenStubController::class . '@ping')),
            [],
            'GET',
        );

        $this->expectException(ControllerExposureViolationException::class);
        $dispatcher->dispatch($match, Request::create('/t', 'GET'));
    }

    public function test_explicit_exposure_allows_controller_when_its_signature_is_in_allowlist(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => true,
                'allowlist' => [
                    SecurityOpenStubController::class . '@ping',
                ],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false],
            'policies' => [],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], '/t', SecurityOpenStubController::class . '@ping')),
            [],
            'GET',
        );

        $response = $dispatcher->dispatch($match, Request::create('/t', 'GET'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('pong', $response->content());
    }

    public function test_explicit_exposure_target_exposed_true_bypasses_allowlist_directly(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => true,
                'allowlist' => [],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false],
            'policies' => [],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $engine = $app->make(\Quantum\Controllers\ControllerEngine::class);
        $target = ControllerTarget::fromDefinition(new ControllerDefinition(SecurityOpenStubController::class . '@ping'))
            ->withExposed(true);

        $reflection = new \ReflectionMethod($engine, 'assertExposure');
        $reflection->setAccessible(true);
        $exception = null;
        try {
            $reflection->invoke($engine, $target, []);
        } catch (\Throwable $e) {
            $exception = $e;
        }
        self::assertNull($exception, 'Expected no exposure exception when target.exposed=true bypasses allowlist');
    }

    public function test_explicit_exposure_target_exposed_false_throws_even_when_controller_in_allowlist(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => true,
                'allowlist' => [
                    SecurityOpenStubController::class . '@ping',
                ],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false],
            'policies' => [],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $engine = $app->make(\Quantum\Controllers\ControllerEngine::class);
        $target = ControllerTarget::fromDefinition(new ControllerDefinition(SecurityOpenStubController::class . '@ping'))
            ->withExposed(false);

        $reflection = new \ReflectionMethod($engine, 'assertExposure');
        $reflection->setAccessible(true);
        $caught = null;
        try {
            $reflection->invoke($engine, $target, []);
        } catch (ControllerExposureViolationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'Expected ControllerExposureViolationException for target.explicit=false even in allowlist');
        self::assertSame('metadata_explicit_unexposed', $caught->reasonCode);
        self::assertSame(SecurityOpenStubController::class . '::ping', $caught->targetSignature);
        self::assertSame('route_metadata_exposed_false', $caught->safeContext['exposure_source'] ?? '');
    }

    public function test_explicit_exposure_allowlist_accepts_invokable_class_name_without_method(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => true,
                'allowlist' => [
                    SecurityInvokableStub::class,
                ],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false],
            'policies' => [],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/t',
            SecurityInvokableStub::class,
        ));
        $match = new RouteMatch($route, [], 'GET');

        $response = $dispatcher->dispatch($match, Request::create('/t', 'GET'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('hi', $response->content());
    }

    public function test_php_attribute_expose_on_controller_class_bypasses_allowlist(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => true,
                'allowlist' => [],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false],
            'policies' => [],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/t',
            ExposedByAttributeClassStub::class . '@open',
        ));
        $match = new RouteMatch($route, [], 'GET');

        $response = $dispatcher->dispatch($match, Request::create('/t', 'GET'));

        self::assertSame(200, $response->statusCode());
    }

    public function test_php_attribute_expose_false_throws_exposure_even_when_in_allowlist(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => true,
                'allowlist' => [
                    UnexposedByAttributeClassStub::class . '@closed',
                ],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false],
            'policies' => [],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/t',
            UnexposedByAttributeClassStub::class . '@closed',
        ));
        $match = new RouteMatch($route, [], 'GET');

        try {
            $dispatcher->dispatch($match, Request::create('/t', 'GET'));
            self::fail('Expected ControllerExposureViolationException from #[Expose(false)]');
        } catch (ControllerExposureViolationException $e) {
            self::assertSame('metadata_explicit_unexposed', $e->reasonCode);
            self::assertSame('route_metadata_exposed_false', $e->safeContext['exposure_source'] ?? 'none');
        }
    }

    public function test_php_attribute_policies_and_permissions_merge_into_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $policy = new class() extends ControllerSecurityPolicy {
            public function id(): string { return 'attribute.echo_policy'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision {
                $meta = $r->metadata;
                $hasPerm = in_array('attr:permission:can_echo', $meta['permissions'] ?? [], true);
                $mentionsPolicy = in_array('attribute.echo_policy', $meta['policies'] ?? [], true);
                if ($hasPerm || $mentionsPolicy) {
                    return SecurityDecision::allow($this, 'has_policy_or_permission', [
                        'got_perm' => $hasPerm,
                        'policies' => $meta['policies'] ?? null,
                        'permissions' => $meta['permissions'] ?? null,
                    ]);
                }
                return SecurityDecision::deny($this, 'missing_metadata', $meta);
            }
        };
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => false,
                'allowlist' => [],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false, 'max_policy_evaluations' => 64],
            'policies' => [$policy],
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/t',
            AttributePoliciesStub::class . '@echo',
        ));
        $match = new RouteMatch(new CompiledRoute($route->definition()), [], 'GET');

        $response = $dispatcher->dispatch($match, Request::create('/t', 'GET'));
        self::assertSame(200, $response->statusCode());
        self::assertSame('echo-ok', $response->content());
    }

    public function test_php_attribute_authentication_required_class_method_override(): void
    {
        $app = new Application(sys_get_temp_dir());
        $noOpPolicies = [
            new class() extends ControllerSecurityPolicy {
                public function id(): string { return 'noop_allow_auth_test'; }
                public function evaluate(SecurityEvaluationRequest $r): SecurityDecision {
                    return SecurityDecision::abstain($this);
                }
            },
        ];
        $app->make(ConfigRepository::class)->set('controller_security', [
            'enabled' => true,
            'controllers' => [
                'explicit_exposure' => false,
                'allowlist' => [],
            ],
            'defaults' => ['deny_by_default' => false, 'fail_closed' => false],
            'authorization' => ['abstain_as_deny' => false, 'max_policy_evaluations' => 64],
            'policies' => $noOpPolicies,
        ]);
        $app->make(ConfigRepository::class)->set('controller_compilation', ['enabled' => false]);
        $dispatcher = $app->make(ControllerDispatcher::class);
        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/t',
            AttributeAuthOverrideStub::class . '@mfaMethod',
        ));
        $match = new RouteMatch(new CompiledRoute($route->definition()), [], 'GET');

        try {
            $dispatcher->dispatch($match, Request::create('/t', 'GET'));
            self::fail('Expected AuthenticationRequiredException for method-level #[AuthenticationRequired(MultiFactor)].');
        } catch (AuthenticationRequiredException $e) {
            self::assertSame('authentication_required', $e->reasonCode);
            $reqStrength = (int) ($e->safeContext['required_strength_value'] ?? 0);
            self::assertSame(AuthenticationStrength::MultiFactor->value, $reqStrength, 'Method #[AuthenticationRequired(MultiFactor)] override must require MFA=30');
        }
    }

    public function test_policyregistry_registerclass_lazy_via_attributepolicyclass_id(): void
    {
        $registry = new ControllerSecurityPolicyRegistry();
        self::assertSame(0, $registry->count());

        $registry->registerClass(LazyWithAttributeStub::class);
        self::assertSame(1, $registry->count());

        $resolved = $registry->resolve('lazy.custom.id');
        self::assertSame('lazy.custom.id', $resolved->id());
        $execution = new ControllerExecutionContext(
            Request::create('/'),
            new RouteMatch(
                (new CompiledRoute(RouteDefinition::make(['GET'], '/', LazyWithAttributeStub::class . '@ping'))),
                [],
                'GET',
            ),
        );
        $definition = new ControllerDefinition(LazyWithAttributeStub::class . '@ping');
        $decision = $resolved->evaluate(new SecurityEvaluationRequest(
            security: (new ControllerSecurityContextFactory(64))->create(Request::create('/'), $execution),
            target: ControllerTarget::fromDefinition($definition),
            action: 'ping',
            resource: [],
            metadata: [],
        ));
        self::assertSame('lazy-value', $decision->obligations['hint'] ?? 'no-hint');
    }

    public function test_policyregistry_registerclass_factory_closure_lazy_resolved_once(): void
    {
        $registry = new ControllerSecurityPolicyRegistry();
        $instantiations = 0;
        $factory = static function () use (&$instantiations): ControllerSecurityPolicy {
            $instantiations++;
            return new #[PolicyClass(id: 'factory-policy')]
            class() extends ControllerSecurityPolicy {
                public function id(): string { return 'factory-policy'; }
                public function evaluate(SecurityEvaluationRequest $r): SecurityDecision {
                    return SecurityDecision::abstain($this);
                }
            };
        };
        $tempInstance = $factory();
        $registry->registerClass(get_class($tempInstance), $factory);

        $firstCallCountAfterReg = $instantiations;
        $reg = $registry->resolve('factory-policy');
        $reg2 = $registry->resolve('factory-policy');
        self::assertSame($reg, $reg2);
        self::assertSame($firstCallCountAfterReg + 1, $instantiations, 'Lazy factory MUST trigger exactly once after register; then cached.');
    }
}

#[Expose]
final class ExposedByAttributeClassStub
{
    public function open(): string
    {
        return 'open-ok';
    }
}

#[Expose(false)]
final class UnexposedByAttributeClassStub
{
    public function closed(): string
    {
        return 'nope';
    }
}

#[Policies(['attribute.echo_policy'])]
#[Permissions(['attr:permission:can_echo'])]
final class AttributePoliciesStub
{
    public function echo(): string
    {
        return 'echo-ok';
    }
}

#[AuthenticationRequired(minimumStrength: AuthenticationStrength::Password)]
final class AttributeAuthOverrideStub
{
    public function passwordMethod(): string { return 'password-ok'; }

    #[AuthenticationRequired(minimumStrength: AuthenticationStrength::MultiFactor)]
    public function mfaMethod(): string { return 'mfa-ok'; }
}

#[PolicyClass(id: 'lazy.custom.id')]
final class LazyWithAttributeStub extends ControllerSecurityPolicy
{
    public function id(): string { return 'lazy.custom.id'; }

    public function evaluate(SecurityEvaluationRequest $r): SecurityDecision
    {
        return $this->allow('ok', ['hint' => 'lazy-value']);
    }
}

final class SecurityOpenStubController
{
    public function ping(): string
    {
        return 'pong';
    }
}

final class SecurityInvokableStub
{
    public function __invoke(): string
    {
        return 'hi';
    }
}
