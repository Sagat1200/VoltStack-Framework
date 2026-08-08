<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Controllers\Security\Budget\ControllerSecurityBudget;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Controllers\Security\Context\Principal;
use Quantum\Controllers\Security\Context\PrincipalType;
use Quantum\Controllers\Security\Context\SecurityAttributes as ContextSecurityAttributes;
use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionCache;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\Policy\ControllerSecurityPolicy;
use Quantum\Controllers\Security\Policy\Composition\PolicyBuilder;
use Quantum\Controllers\Security\Policy\Composition\PolicyExpressionResolver;
use Quantum\Controllers\Security\ControllerTarget;
use Quantum\Controllers\ControllerDefinition;

final class PolicyCompositionTest extends TestCase
{
    private function createRequest(array $roles = [], array $permissions = [], array $attributes = [], bool $authenticated = true): SecurityEvaluationRequest
    {
        $target = ControllerTarget::fromDefinition(new ControllerDefinition('App\\Demo@index'));
        $principal = new Principal(
            id: $authenticated ? 'u-1' : 'guest',
            type: $authenticated ? PrincipalType::User : PrincipalType::Guest,
            authenticated: $authenticated,
            claims: array_merge(
                $roles ? ['roles' => $roles] : [],
                $permissions ? ['permissions' => $permissions] : [],
            ),
        );
        $attrs = new ContextSecurityAttributes($attributes);
        $ctx = new ControllerSecurityContext(
            principal: $principal,
            tenant: null,
            authenticationStrength: $authenticated ? AuthenticationStrength::Password : AuthenticationStrength::None,
            attributes: $attrs,
            decisions: new SecurityDecisionCache(4096),
            executionId: 'exec-' . uniqid('', true),
            budget: new ControllerSecurityBudget(512),
        );
        return new SecurityEvaluationRequest($ctx, $target, 'index', 'demo.resource', []);
    }

    private function stubPolicy(string $id, SecurityDecisionEffect $effect, string $reason = ''): ControllerSecurityPolicyInterface
    {
        return new class($id, $effect, $reason) implements ControllerSecurityPolicyInterface {
            public function __construct(
                private readonly string $pid,
                private readonly SecurityDecisionEffect $fx,
                private readonly string $reason = '',
            ) {}
            public function id(): string { return $this->pid; }
            public function evaluate(SecurityEvaluationRequest $request): SecurityDecision {
                return new SecurityDecision($this->fx, $this->pid, $this->reason, []);
            }
        };
    }

    public function test_all_of_requires_every_child_allow_to_pass(): void
    {
        $p1 = $this->stubPolicy('p1', SecurityDecisionEffect::Allow, 'allowed_by_p1');
        $p2 = $this->stubPolicy('p2', SecurityDecisionEffect::Allow, 'allowed_by_p2');
        $p3 = $this->stubPolicy('p3', SecurityDecisionEffect::Deny, 'blocked_by_p3');

        $builder = PolicyBuilder::create();
        $builder->allOf([$p1, $p2], 'comp.all_pass');
        $allow = $builder->last();
        $builder->allOf([$p1, $p2, $p3], 'comp.one_deny');
        $deny = $builder->last();

        $req = $this->createRequest();
        $d1 = $allow->evaluate($req);
        $d2 = $deny->evaluate($req);

        self::assertTrue($d1->isAllow(), 'all_of should pass when all children allow');
        self::assertSame('all_of_all_children_allowed', $d1->reasonCode);
        self::assertFalse($d2->isAllow(), 'all_of should fail when any child denies');
        self::assertSame('all_of_child_denied', $d2->reasonCode);
        self::assertSame('p3', $d2->obligations['child_policy_id'] ?? null);
    }

    public function test_any_of_first_allow_is_sufficient(): void
    {
        $deny = $this->stubPolicy('d1', SecurityDecisionEffect::Deny, 'first_no');
        $deny2 = $this->stubPolicy('d2', SecurityDecisionEffect::Deny, 'second_no');
        $allow = $this->stubPolicy('a1', SecurityDecisionEffect::Allow, 'third_yes');

        $builder = PolicyBuilder::create();
        $builder->anyOf([$deny, $deny2, $allow], 'comp.any_pass');
        $pass = $builder->last();
        $builder->anyOf([$deny, $deny2], 'comp.any_fail');
        $fail = $builder->last();

        $req = $this->createRequest();
        $dpass = $pass->evaluate($req);
        $dfail = $fail->evaluate($req);

        self::assertTrue($dpass->isAllow(), 'any_of should pass on first allow');
        self::assertSame('any_of_child_allowed', $dpass->reasonCode);
        self::assertFalse($dfail->isAllow());
    }

    public function test_not_inverts_allow_and_deny(): void
    {
        $allow = $this->stubPolicy('allow', SecurityDecisionEffect::Allow, 'origin_allowed');
        $deny = $this->stubPolicy('deny', SecurityDecisionEffect::Deny, 'origin_denied');

        $builder = PolicyBuilder::create();
        $builder->not($allow, 'comp.not_allow');
        $notAllow = $builder->last();
        $builder->not($deny, 'comp.not_deny');
        $notDeny = $builder->last();

        $req = $this->createRequest();
        $d1 = $notAllow->evaluate($req);
        $d2 = $notDeny->evaluate($req);

        self::assertFalse($d1->isAllow(), 'NOT allow must be deny');
        self::assertSame('not_child_allowed_inverted_to_deny', $d1->reasonCode);
        self::assertTrue($d2->isAllow(), 'NOT deny must be allow');
        self::assertSame('not_child_denied_inverted_to_allow', $d2->reasonCode);
    }

    public function test_at_least_one_treshold_2_needs_two_allows(): void
    {
        $a1 = $this->stubPolicy('a1', SecurityDecisionEffect::Allow, 'yes1');
        $a2 = $this->stubPolicy('a2', SecurityDecisionEffect::Allow, 'yes2');
        $d = $this->stubPolicy('d', SecurityDecisionEffect::Deny, 'no');

        $builder = PolicyBuilder::create();
        $builder->atLeastOne([$a1, $a2, $d], minimum: 2, id: 'comp.min2_ok');
        $pass = $builder->last();
        $builder->atLeastOne([$a1, $d], minimum: 2, id: 'comp.min2_fail');
        $fail = $builder->last();

        $req = $this->createRequest();
        $dpass = $pass->evaluate($req);
        $dfail = $fail->evaluate($req);

        self::assertTrue($dpass->isAllow());
        self::assertSame(2, $dpass->obligations['actual'] ?? null);
        self::assertFalse($dfail->isAllow());
        self::assertSame('at_least_one_threshold_not_met', $dfail->reasonCode);
    }

    public function test_weighted_voting_ratio_51_percent(): void
    {
        $a1 = $this->stubPolicy('a1', SecurityDecisionEffect::Allow, 'a1');
        $a2 = $this->stubPolicy('a2', SecurityDecisionEffect::Allow, 'a2');
        $d1 = $this->stubPolicy('d1', SecurityDecisionEffect::Deny, 'd1');
        $d2 = $this->stubPolicy('d2', SecurityDecisionEffect::Deny, 'd2');
        $d3 = $this->stubPolicy('d3', SecurityDecisionEffect::Deny, 'd3');

        $weightsPass = ['a1' => 50, 'a2' => 2];
        $weightsFail = ['a1' => 50, 'd1' => 20, 'd2' => 20, 'd3' => 10];
        $builder = PolicyBuilder::create();
        $builder->weightedVoting([$a1, $a2, $d1], weights: $weightsPass, approvalRatio: 0.51, id: 'comp.wv_pass');
        $pass = $builder->last();
        $builder->weightedVoting([$a1, $d1, $d2, $d3], weights: $weightsFail, approvalRatio: 0.51, id: 'comp.wv_fail');
        $fail = $builder->last();

        $req = $this->createRequest();
        $dpass = $pass->evaluate($req);
        $dfail = $fail->evaluate($req);

        self::assertTrue($dpass->isAllow(), sprintf('Weighted pass failed: approval=%s', $dpass->obligations['approval_actual'] ?? '?'));
        self::assertSame('weighted_voting_passed', $dpass->reasonCode);
        self::assertFalse($dfail->isAllow());
        self::assertSame('weighted_voting_failed', $dfail->reasonCode);
        $approvalFail = $dfail->obligations['approval_actual'] ?? 1;
        self::assertLessThan(0.51, $approvalFail);
    }

    public function test_expression_parser_role_admin_and_scope_read_or_owner(): void
    {
        $resolver = PolicyExpressionResolver::default();
        $expr = 'role:admin && (scope:read || owner:true)';
        $policy = $resolver->parse($expr, 'composed.admin_or_owner');

        $reqAdminOwner = $this->createRequest(roles: ['admin'], attributes: ['owner' => true, 'scopes' => ['read']]);
        $reqAdminNoScopeNoOwner = $this->createRequest(roles: ['admin'], attributes: ['scopes' => ['write']]);
        $reqUserOwnerScopeRead = $this->createRequest(roles: ['user'], attributes: ['owner' => true, 'scopes' => ['read']]);

        $d1 = $policy->evaluate($reqAdminOwner);
        $d2 = $policy->evaluate($reqAdminNoScopeNoOwner);
        $d3 = $policy->evaluate($reqUserOwnerScopeRead);

        self::assertTrue($d1->isAllow(), 'Admin+owner+read should match expression');
        self::assertFalse($d2->isAllow(), 'Admin without owner or read scope should not match');
        self::assertFalse($d3->isAllow(), 'User without role:admin should not match even if owner');
    }

    public function test_expression_parser_negated_term_and_nested_groups(): void
    {
        $resolver = PolicyExpressionResolver::default();
        $expr = '(role:admin && !role:banned) || (role:support && attr_verified:true)';
        $policy = $resolver->parse($expr, 'composed.complex');

        $adminClean = $this->createRequest(roles: ['admin']);
        $adminBanned = $this->createRequest(roles: ['admin', 'banned']);
        $supportVerified = $this->createRequest(roles: ['support'], attributes: ['attr_verified' => 'true']);
        $supportUnverified = $this->createRequest(roles: ['support'], attributes: ['attr_verified' => 'false']);

        $d1 = $policy->evaluate($adminClean);
        $d2 = $policy->evaluate($adminBanned);
        $d3 = $policy->evaluate($supportVerified);
        $d4 = $policy->evaluate($supportUnverified);

        self::assertTrue($d1->isAllow());
        self::assertFalse($d2->isAllow(), 'Banned admin must be denied via !role:banned');
        self::assertTrue($d3->isAllow());
        self::assertFalse($d4->isAllow());
    }

    public function test_builder_all_methods_are_chainable_and_produce_policy_instances(): void
    {
        $allow1 = $this->stubPolicy('a1', SecurityDecisionEffect::Allow, 'a1');
        $allow2 = $this->stubPolicy('a2', SecurityDecisionEffect::Allow, 'a2');
        $deny1 = $this->stubPolicy('d1', SecurityDecisionEffect::Deny, 'd1');

        $builder = PolicyBuilder::create();
        $result = $builder
            ->allOf([$allow1, $allow2], 'x1')
            ->anyOf([$deny1, $allow1], 'x2')
            ->not($deny1, 'x3')
            ->atLeastOne([$allow1, $allow2, $deny1], minimum: 2, id: 'x4')
            ->weightedVoting([$allow1, $allow2], ['a1' => 2, 'a2' => 3], 0.5, 'x5')
            ->parse('role:admin || permission:dashboard', 'x6');

        self::assertSame($builder, $result, 'PolicyBuilder must return fluent chainable self');

        $all = $builder->all();
        self::assertCount(6, $all, 'Builder.all() must return 6 policies after chain');
        foreach ($all as $p) {
            self::assertInstanceOf(ControllerSecurityPolicyInterface::class, $p);
        }

        $req = $this->createRequest(roles: ['admin']);
        $last = $builder->last();
        self::assertNotNull($last);
        $dl = $last->evaluate($req);
        self::assertTrue($dl->isAllow(), 'role:admin expression must allow admin principal');
    }
}
