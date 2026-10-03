<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\CachedAuthorityRepository;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\RequestScopedAuthorityMemoizationCache;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Context\TenantScopeResolver;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Core\AuthorizationManager;
use Quantum\Authorization\Core\AuthorizationPlanner;
use Quantum\Authorization\Core\AuthorizationRequestFactory;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Context\AuthorizationContextFactory;
use Quantum\Authorization\Principal\Principal;
use VoltStack\Framework\Application;

final class AuthorizationManagerAuthorityEarlyGateTest extends TestCase
{
    public function test_early_gate_returns_allow_when_permission_granted_in_authority_before_planner(): void
    {
        $principal = new Principal('u_1');
        $scope = new Scope(Scope::GLOBAL);
        $authority = new InMemoryAuthorityRepository([
            [
                'principal_id' => 'u_1',
                'permissions' => ['posts.create'],
            ],
        ]);

        $expectedPlannerCalls = 0;
        $manager = $this->makeManagerWithNullPlannerSpy($authority, true, $expectedPlannerCalls);
        $decision = $manager->decide('posts.create', null, null, $principal);

        self::assertTrue($decision->isAllowed());
        self::assertSame('authorization.authority.early_gate', $decision->source());
        self::assertSame('authority_permission_granted', $decision->reasonCode());
        $md = $decision->metadata();
        self::assertTrue($md['authority_early_gate'] ?? false);
        self::assertSame('u_1', $md['principal_id'] ?? null);
        self::assertSame('global', $md['scope'] ?? null);
    }
    public function test_early_gate_skips_to_planner_when_authority_does_not_grant(): void
    {
        $authority = new InMemoryAuthorityRepository([
            [
                'principal_id' => 'u_1',
                'permissions' => ['posts.view'],
            ],
        ]);

        $plannerCalled = 0;
        $fallbackDecision = \Quantum\Authorization\Decision\DecisionResult::allow('planner.policy', 'policy_override');
        $manager = $this->makeManagerWithFallbackPlanner($authority, true, $fallbackDecision, $plannerCalled);

        $principal = new Principal('u_1');
        $decision = $manager->decide('posts.update', null, null, $principal);

        self::assertGreaterThan(0, $plannerCalled);
        self::assertSame('planner.policy', $decision->source());
    }

    public function test_early_gate_flag_disabled_does_not_short_circuit_even_when_authority_grants(): void
    {
        $plannerCalled = 0;
        $fallbackDecision = \Quantum\Authorization\Decision\DecisionResult::deny('planner', 'gate_override');
        $authority = new InMemoryAuthorityRepository([
            [
                'principal_id' => 'u_1',
                'permissions' => ['posts.create'],
            ],
        ]);
        $manager = $this->makeManagerWithFallbackPlanner($authority, false, $fallbackDecision, $plannerCalled);

        $decision = $manager->decide('posts.create', null, null, new Principal('u_1'));

        self::assertGreaterThan(0, $plannerCalled);
        self::assertTrue($decision->isDenied());
    }

    public function test_null_authority_keeps_manager_working_via_planner_without_gate(): void
    {
        $plannerCalled = 0;
        $fallback = \Quantum\Authorization\Decision\DecisionResult::allow('planner.allow', 'defaulted');
        $manager = $this->makeManagerWithFallbackPlanner(null, true, $fallback, $plannerCalled);

        $decision = $manager->decide('anything', null, null, new Principal('u_1'));

        self::assertGreaterThan(0, $plannerCalled);
        self::assertTrue($decision->isAllowed());
    }

    public function test_principal_extraction_works_for_string_int_principal_object_and_get_id(): void
    {
        $grants = [
            ['principal_id' => 'u_string', 'permissions' => ['posts.string']],
            ['principal_id' => '123', 'permissions' => ['posts.int']],
            ['principal_id' => 'u_object', 'permissions' => ['posts.object']],
            ['principal_id' => 'u_get_id', 'permissions' => ['posts.getid']],
        ];
        $authority = new InMemoryAuthorityRepository($grants);

        $calls = 0;
        $fallback = \Quantum\Authorization\Decision\DecisionResult::deny('planner', 'no_grant');
        $manager = $this->makeManagerWithFallbackPlanner($authority, true, $fallback, $calls);

        self::assertTrue($manager->check('posts.string', null, null, 'u_string'));
        self::assertTrue($manager->check('posts.int', null, null, 123));
        self::assertTrue($manager->check('posts.object', null, null, new Principal('u_object')));

        $objectWithId = new class {
            public function getId(): string { return 'u_get_id'; }
        };
        self::assertTrue($manager->check('posts.getid', null, null, $objectWithId));
    }

    public function test_scope_extracted_from_context_attribute_and_global_default(): void
    {
        $authority = new InMemoryAuthorityRepository([
            ['principal_id' => 'u_1', 'permissions' => ['posts.globalOnly']],
            ['principal_id' => 'u_1', 'scope' => 'tenant:acme', 'permissions' => ['posts.tenantOnly']],
        ]);
        $globalScope = new Scope(Scope::GLOBAL);
        $tenantScope = new Scope('tenant:acme');

        $calls = 0;
        $fallback = \Quantum\Authorization\Decision\DecisionResult::deny('planner', 'deny');
        $manager = $this->makeManagerWithFallbackPlanner($authority, true, $fallback, $calls);

        $global = $manager->check('posts.globalOnly', null, null, new Principal('u_1'));
        $scoped = $manager->check('posts.tenantOnly', null, $this->contextWithScope($tenantScope), new Principal('u_1'));
        $crossScope = $manager->check('posts.tenantOnly', null, null, new Principal('u_1'));

        self::assertTrue($global);
        self::assertTrue($scoped);
        self::assertFalse($crossScope);
    }

    public function test_invalid_ability_or_whitespace_do_not_throw_they_continue_to_planner(): void
    {
        $authority = new InMemoryAuthorityRepository([
            ['principal_id' => 'u_1', 'permissions' => ['posts.view']],
        ]);
        $calls = 0;
        $fallback = \Quantum\Authorization\Decision\DecisionResult::allow('planner', 'fallback_allowed');
        $manager = $this->makeManagerWithFallbackPlanner($authority, true, $fallback, $calls);

        $decision1 = $manager->decide('posts.edit.not.in.authority', null, null, new Principal('u_1'));
        $decision2 = $manager->decide(new Ability('valid.name', []), null, null, new Principal('u_1'));

        self::assertGreaterThan(0, $calls);
        self::assertTrue($decision1->isAllowed());
        self::assertSame('fallback_allowed', $decision1->reasonCode());
        self::assertTrue($decision2->isAllowed());
    }

    public function test_scope_can_be_derived_automatically_from_tenant_id_when_resolver_is_enabled(): void
    {
        $authority = new InMemoryAuthorityRepository([
            ['principal_id' => 'u_1', 'scope' => 'tenant:acme', 'permissions' => ['posts.tenantOnly']],
        ]);
        $calls = 0;
        $fallback = \Quantum\Authorization\Decision\DecisionResult::deny('planner', 'deny');
        $manager = $this->makeManagerWithFallbackPlanner(
            $authority,
            true,
            $fallback,
            $calls,
            new TenantScopeResolver(),
        );

        $allowed = $manager->check(
            'posts.tenantOnly',
            null,
            AuthorizationContext::empty()->withAttributes(['tenant.id' => 'acme']),
            new Principal('u_1'),
        );

        self::assertTrue($allowed);
        self::assertSame(0, $calls);
    }

    private function makeManagerWithNullPlannerSpy(
        ?AuthorityRepositoryInterface $authority,
        bool $earlyGateEnabled,
        int &$expectedPlannerCalls,
        ?TenantScopeResolver $tenantScopeResolver = null,
    ): mixed {
        $expectedPlannerCalls = 0;
        $key = 'authz_nullplanner_' . bin2hex(random_bytes(4));
        $GLOBALS[$key] = 0;
        $planner = new class($key) implements \Quantum\Authorization\Contracts\AuthorizationPlannerInterface {
            public function __construct(private readonly string $key) {}
            public function plan(\Quantum\Authorization\Core\AuthorizationRequest $request): array {
                $GLOBALS[$this->key] = ($GLOBALS[$this->key] ?? 0) + 1;
                return [];
            }
            public function evaluate(\Quantum\Authorization\Core\AuthorizationRequest $request): \Quantum\Authorization\Decision\DecisionResult {
                $GLOBALS[$this->key] = ($GLOBALS[$this->key] ?? 0) + 1;
                return \Quantum\Authorization\Decision\DecisionResult::deny('planner', 'never_reached');
            }
        };
        $app = new Application(sys_get_temp_dir());
        $factory = $app->make(AuthorizationRequestFactory::class);
        $innerManager = new AuthorizationManager($factory, $planner, $authority, $earlyGateEnabled, $tenantScopeResolver);
        register_shutdown_function(static function () use ($key): void { unset($GLOBALS[$key], $GLOBALS[$key . '_refholder']); });

        return new class($innerManager, $key, $expectedPlannerCalls) implements \Quantum\Authorization\Contracts\AuthorizationManagerInterface {
            public function __construct(
                private readonly AuthorizationManager $inner,
                private readonly string $key,
                mixed &$externalCounterHolderRef,
            ) {
                $externalCounterHolderRef = 0;
                $GLOBALS[$this->key . '_refholder'] = &$externalCounterHolderRef;
            }

            public function for(mixed $principal): \Quantum\Authorization\Core\BoundAuthorization
            {
                $result = $this->inner->for($principal);
                $this->sync();
                return $result;
            }

            public function check(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): bool
            {
                $result = $this->inner->check($ability, $subject, $context, $principal);
                $this->sync();
                return $result;
            }

            public function cannot(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): bool
            {
                $result = $this->inner->cannot($ability, $subject, $context, $principal);
                $this->sync();
                return $result;
            }

            public function decide(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): \Quantum\Authorization\Decision\DecisionResult
            {
                $result = $this->inner->decide($ability, $subject, $context, $principal);
                $this->sync();
                return $result;
            }

            public function authorize(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): \Quantum\Authorization\Decision\DecisionResult
            {
                $result = $this->inner->authorize($ability, $subject, $context, $principal);
                $this->sync();
                return $result;
            }

            private function sync(): void
            {
                $val = (int) ($GLOBALS[$this->key] ?? 0);
                if (isset($GLOBALS[$this->key . '_refholder']) && !is_array($GLOBALS[$this->key . '_refholder'])) {
                    $refHolder = &$GLOBALS[$this->key . '_refholder'];
                    $refHolder = $val;
                }
            }
        };
    }

    private function makeManagerWithFallbackPlanner(
        ?AuthorityRepositoryInterface $authority,
        bool $earlyGateEnabled,
        \Quantum\Authorization\Decision\DecisionResult $fallback,
        int &$plannerCalls,
        ?TenantScopeResolver $tenantScopeResolver = null,
    ): mixed {
        $plannerCalls = 0;
        $key = 'authz_fallback_' . bin2hex(random_bytes(4));
        $GLOBALS[$key] = 0;
        $planner = new class($fallback, $key) implements \Quantum\Authorization\Contracts\AuthorizationPlannerInterface {
            private \Quantum\Authorization\Decision\DecisionResult $fallback;
            public function __construct(
                \Quantum\Authorization\Decision\DecisionResult $fallback,
                private readonly string $key,
            ) {
                $this->fallback = $fallback;
            }
            public function plan(\Quantum\Authorization\Core\AuthorizationRequest $request): array {
                $GLOBALS[$this->key] = ($GLOBALS[$this->key] ?? 0) + 1;
                return [];
            }
            public function evaluate(\Quantum\Authorization\Core\AuthorizationRequest $request): \Quantum\Authorization\Decision\DecisionResult {
                $GLOBALS[$this->key] = ($GLOBALS[$this->key] ?? 0) + 1;
                return $this->fallback;
            }
        };
        $app = new Application(sys_get_temp_dir());
        $factory = $app->make(AuthorizationRequestFactory::class);
        $innerManager = new AuthorizationManager($factory, $planner, $authority, $earlyGateEnabled, $tenantScopeResolver);
        register_shutdown_function(static function () use ($key): void { unset($GLOBALS[$key], $GLOBALS[$key . '_refholder']); });

        return new class($innerManager, $key, $plannerCalls) implements \Quantum\Authorization\Contracts\AuthorizationManagerInterface {
            public function __construct(
                private readonly AuthorizationManager $inner,
                private readonly string $key,
                mixed &$externalCounterHolderRef,
            ) {
                $externalCounterHolderRef = 0;
                $GLOBALS[$this->key . '_refholder'] = &$externalCounterHolderRef;
            }

            public function for(mixed $principal): \Quantum\Authorization\Core\BoundAuthorization
            {
                $result = $this->inner->for($principal);
                $this->sync();
                return $result;
            }

            public function check(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): bool
            {
                $result = $this->inner->check($ability, $subject, $context, $principal);
                $this->sync();
                return $result;
            }

            public function cannot(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): bool
            {
                $result = $this->inner->cannot($ability, $subject, $context, $principal);
                $this->sync();
                return $result;
            }

            public function decide(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): \Quantum\Authorization\Decision\DecisionResult
            {
                $result = $this->inner->decide($ability, $subject, $context, $principal);
                $this->sync();
                return $result;
            }

            public function authorize(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): \Quantum\Authorization\Decision\DecisionResult
            {
                $result = $this->inner->authorize($ability, $subject, $context, $principal);
                $this->sync();
                return $result;
            }

            private function sync(): void
            {
                $val = (int) ($GLOBALS[$this->key] ?? 0);
                if (isset($GLOBALS[$this->key . '_refholder']) && !is_array($GLOBALS[$this->key . '_refholder'])) {
                    $refHolder = &$GLOBALS[$this->key . '_refholder'];
                    $refHolder = $val;
                }
            }
        };
    }

    private function contextWithScope(Scope $scope): AuthorizationContext
    {
        return AuthorizationContext::empty()->withAttributes(['authorization.scope' => $scope]);
    }
}
