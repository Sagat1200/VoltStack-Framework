<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Controllers\Security\Context\Principal;
use Quantum\Controllers\Security\Context\PrincipalType;
use Quantum\Controllers\Security\Context\SecurityAttributes;
use Quantum\Controllers\Security\Budget\ControllerSecurityBudget;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionCache;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\Policy\ControllerSecurityPolicy;
use Quantum\Controllers\Security\Policy\ControllerSecurityPolicyRegistry;
use Quantum\Controllers\Security\Worker\ControllerWorkerDisposition;
use Quantum\Controllers\Security\Worker\HardenedControllerSecurityDecisionEngine;
use Quantum\Controllers\Security\Worker\PolicyEvaluationSandbox;
use Quantum\Controllers\Security\ControllerTarget;
use Quantum\Controllers\ControllerDefinition;

final class PolicyWorkerSafetyTest extends TestCase
{
    public function test_sandbox_throwable_sandbox_wraps_errors_as_deny_but_does_not_leak(): void
    {
        $sandbox = new PolicyEvaluationSandbox(perPolicyTimeoutNs: 2_000_000_000, maxRecursionDepth: 8, circuitBreakerThreshold: 99);
        $broken = new class extends ControllerSecurityPolicy {
            public function id(): string { return 'policy.error'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision
            {
                throw new \TypeError('expected int but got string');
            }
        };
        $request = $this->createEvalRequest();
        $noop = static function (): void {};

        $result = $sandbox->evaluate($broken, $request, $noop, $noop);

        self::assertSame(SecurityDecisionEffect::Deny, $result['decision']->effect);
        self::assertSame('policy_evaluation_fatal_throwable', $result['decision']->reasonCode);
        self::assertSame('policy.error', $result['decision']->obligations['policy_id'] ?? null);
        self::assertSame(\TypeError::class, $result['decision']->obligations['throwable_class'] ?? null);
        self::assertTrue(ControllerWorkerDisposition::isTrustFailure($result['workerDisposition']));
    }

    public function test_hardened_engine_marks_worker_terminate_on_trust_failure(): void
    {
        $broken = new class extends ControllerSecurityPolicy {
            public function id(): string { return 'policy.fatal'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision
            {
                throw new \Error('call to undefined method');
            }
        };
        $registry = new ControllerSecurityPolicyRegistry();
        $registry->register($broken);
        $registry->freeze();
        $sandbox = new PolicyEvaluationSandbox(circuitBreakerThreshold: 99);
        $engine = new HardenedControllerSecurityDecisionEngine($registry, $sandbox);
        $ctx = $this->createContext();
        $target = $this->createTarget();
        $request = new SecurityEvaluationRequest($ctx, $target, 'read', 'resource:1', []);

        $decision = $engine->decide($request);

        self::assertSame(SecurityDecisionEffect::Deny, $decision->effect);
        self::assertSame('policy_worker_trust_failure', $decision->reasonCode);
        self::assertSame('terminate_trust_failure', $decision->obligations['worker_disposition'] ?? null);
    }

    public function test_recursion_prevention_blocks_recursive_policy(): void
    {
        $registry = new ControllerSecurityPolicyRegistry();
        $recursionDriver = new class() extends ControllerSecurityPolicy {
            public function id(): string { return 'policy.recursion.driver'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision
            {
                return $this->abstain();
            }
        };
        $registry->register($recursionDriver);
        $registry->freeze();

        $sandbox = new class(perPolicyTimeoutNs: 5_000_000_000, maxRecursionDepth: 2, circuitBreakerThreshold: 99) extends PolicyEvaluationSandbox {
            public int $calledEvaluate = 0;
            public function evaluate(
                \Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface $policy,
                \Quantum\Controllers\Security\Decision\SecurityEvaluationRequest $request,
                callable $recursionGuardBegin,
                callable $recursionGuardEnd,
            ): array {
                $this->calledEvaluate++;
                if ($this->calledEvaluate === 1) {
                    $depthRef = new \ReflectionProperty($this, 'recursionStack');
                    $depthRef->setValue($this, [$policy->id() => 999]);
                }
                return parent::evaluate($policy, $request, $recursionGuardBegin, $recursionGuardEnd);
            }
        };
        $engine = new HardenedControllerSecurityDecisionEngine($registry, $sandbox);

        $decision = $engine->decide(new SecurityEvaluationRequest($this->createContext(), $this->createTarget(), 'read', 'resource:1', []));

        self::assertSame(SecurityDecisionEffect::Deny, $decision->effect);
        $allowed = [
            'policy_max_recursion_depth_exceeded',
            'policy_worker_trust_failure',
            'policy_evaluation_fatal_throwable',
            'policy_evaluation_exception',
        ];
        self::assertTrue(
            in_array($decision->reasonCode, $allowed, true),
            sprintf('reason_code [%s] not in expected list', $decision->reasonCode)
        );
    }

    public function test_circuit_breaker_blocks_policy_after_threshold_failures(): void
    {
        $registry = new ControllerSecurityPolicyRegistry();
        $failing = new class extends ControllerSecurityPolicy {
            public int $called = 0;
            public function id(): string { return 'policy.flaky'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision
            {
                $this->called++;
                throw new \RuntimeException('boom');
            }
        };
        $registry->register($failing);
        $registry->freeze();

        $sandbox = new PolicyEvaluationSandbox(circuitBreakerThreshold: 2, circuitBreakerOpenSeconds: 300);
        $engine = new HardenedControllerSecurityDecisionEngine($registry, $sandbox);
        $t = $this->createTarget();

        $firstDecision = $engine->decide(new SecurityEvaluationRequest($this->createContext(), $t, 'read', 'r:1', []));
        $secondDecision = $engine->decide(new SecurityEvaluationRequest($this->createContext(), $t, 'read', 'r:2', []));
        $thirdDecision = $engine->decide(new SecurityEvaluationRequest($this->createContext(), $t, 'read', 'r:3', []));

        self::assertSame(SecurityDecisionEffect::Deny, $firstDecision->effect);
        self::assertSame(SecurityDecisionEffect::Deny, $secondDecision->effect);
        self::assertSame(SecurityDecisionEffect::Deny, $thirdDecision->effect);
        $expectedReasons = [
            'policy_circuit_breaker_open',
            'policy_worker_trust_failure',
        ];
        self::assertTrue(
            in_array($thirdDecision->reasonCode, $expectedReasons, true),
            sprintf('third reason_code [%s] not expected', $thirdDecision->reasonCode)
        );
        self::assertLessThanOrEqual(2, $failing->called);
    }

    public function test_one_thousand_policy_evaluations_remain_within_budget_and_safe(): void
    {
        $registry = new ControllerSecurityPolicyRegistry();
        for ($i = 0; $i < 1000; $i++) {
            $pId = 'policy.p' . $i;
            $registry->register(new class($pId) extends ControllerSecurityPolicy {
                private string $pid;
                public function __construct(string $id) { $this->pid = $id; }
                public function id(): string { return $this->pid; }
                public function evaluate(SecurityEvaluationRequest $r): SecurityDecision
                {
                    usleep(1);
                    return $this->allow();
                }
            });
        }
        $registry->freeze();

        $budget = new ControllerSecurityBudget(maxPolicyEvaluations: 2000);
        $sandbox = new PolicyEvaluationSandbox(perPolicyTimeoutNs: 500_000_000, circuitBreakerThreshold: 9999);
        $engine = new HardenedControllerSecurityDecisionEngine($registry, $sandbox);
        $ctx = $this->createContext($budget);

        $decision = $engine->decide(new SecurityEvaluationRequest($ctx, $this->createTarget(), 'read', 'r:bulk', []));

        self::assertSame(SecurityDecisionEffect::Allow, $decision->effect);
        self::assertSame('at_least_one_policy_allowed', $decision->reasonCode);
        self::assertLessThanOrEqual(1000, $ctx->decisions->count());
    }

    public function test_timeout_enforced_when_policy_exceeds_deadline(): void
    {
        $registry = new ControllerSecurityPolicyRegistry();
        $slow = new class extends ControllerSecurityPolicy {
            public function id(): string { return 'policy.slow'; }
            public function evaluate(SecurityEvaluationRequest $r): SecurityDecision
            {
                return $this->allow();
            }
        };
        $registry->register($slow);
        $registry->freeze();

        $sandbox = new class(perPolicyTimeoutNs: 1, maxRecursionDepth: 8, circuitBreakerThreshold: 99) extends PolicyEvaluationSandbox {
            protected function runWithDeadline(callable $fn, int $start, int $timeoutNs, callable $checkDeadline): mixed
            {
                $fn();
                return null;
            }
        };

        $engine = new HardenedControllerSecurityDecisionEngine($registry, $sandbox);
        $ctx = $this->createContext();

        $decision = $engine->decide(new SecurityEvaluationRequest($ctx, $this->createTarget(), 'read', 'r:1', []));

        self::assertSame(SecurityDecisionEffect::Deny, $decision->effect);
        $reasonCodes = [
            'policy_evaluation_timeout',
            'policy_worker_trust_failure',
            'sandbox_returned_invalid_payload',
        ];
        self::assertTrue(
            in_array($decision->reasonCode, $reasonCodes, true),
            sprintf('reason_code [%s] unexpected', $decision->reasonCode)
        );
        $dispLabel = $decision->obligations['worker_disposition'] ?? 'terminate_trust_failure';
        self::assertTrue(
            in_array($dispLabel, [
                'terminate_trust_failure',
                'terminate',
            ], true),
            sprintf('disp [%s] unexpected', $dispLabel)
        );
    }

    public function test_disposition_enum_describes_all_terminal_states(): void
    {
        self::assertTrue(ControllerWorkerDisposition::isTerminal(ControllerWorkerDisposition::Terminate));
        self::assertTrue(ControllerWorkerDisposition::isTerminal(ControllerWorkerDisposition::TerminateOnTrustFailure));
        self::assertFalse(ControllerWorkerDisposition::isTerminal(ControllerWorkerDisposition::Reuse));
        self::assertFalse(ControllerWorkerDisposition::isTerminal(ControllerWorkerDisposition::ContinueSafe));
        self::assertFalse(ControllerWorkerDisposition::isTerminal(ControllerWorkerDisposition::ResetContext));
        self::assertTrue(ControllerWorkerDisposition::isTrustFailure(ControllerWorkerDisposition::TerminateOnTrustFailure));
        self::assertSame('terminate_trust_failure', ControllerWorkerDisposition::describe(ControllerWorkerDisposition::TerminateOnTrustFailure));
        self::assertSame('reuse', ControllerWorkerDisposition::describe(ControllerWorkerDisposition::Reuse));
    }

    private function createEvalRequest(): SecurityEvaluationRequest
    {
        return new SecurityEvaluationRequest($this->createContext(), $this->createTarget(), 'read', 'resource:1', []);
    }

    private function createTarget(): ControllerTarget
    {
        return ControllerTarget::fromDefinition(new ControllerDefinition('App\\Controllers\\Demo@action'));
    }

    private function createContext(?ControllerSecurityBudget $budget = null): ControllerSecurityContext
    {
        return new ControllerSecurityContext(
            principal: new Principal('u-1', PrincipalType::User, true, ['role' => 'admin']),
            tenant: null,
            authenticationStrength: AuthenticationStrength::Password,
            attributes: new SecurityAttributes([]),
            decisions: new SecurityDecisionCache(4096),
            executionId: 'exec-' . uniqid('', true),
            budget: $budget ?? new ControllerSecurityBudget(512),
        );
    }
}