<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Worker;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Throwable;

class PolicyEvaluationSandbox
{
    /**
     * @var array<string, array{failures: int, open: bool, openedAt: int, rejectUntil: int}>
     */
    protected array $circuitBreakers = [];

    /**
     * @var array<string, int>
     */
    protected array $recursionStack = [];

    public function __construct(
        private readonly int $perPolicyTimeoutNs = 25_000_000,
        private readonly int $maxRecursionDepth = 8,
        private readonly int $circuitBreakerThreshold = 5,
        private readonly int $circuitBreakerOpenSeconds = 30,
    ) {}

    /**
     * @return array{decision: SecurityDecision, workerDisposition: int, elapsedNs: int}
     */
    public function evaluate(
        ControllerSecurityPolicyInterface $policy,
        SecurityEvaluationRequest $request,
        callable $recursionGuardBegin,
        callable $recursionGuardEnd,
    ): array {
        $policyId = $policy->id();
        $start = hrtime(true);

        if ($this->isCircuitOpen($policyId, $start)) {
            return [
                SecurityDecision::deny(
                    policyId: 'security.circuit_breaker',
                    reasonCode: 'policy_circuit_breaker_open',
                    obligations: [
                        'policy_id' => $policyId,
                        'failures' => $this->circuitBreakers[$policyId]['failures'] ?? $this->circuitBreakerThreshold,
                    ],
                ),
                ControllerWorkerDisposition::ContinueSafe,
                0,
            ];
        }

        if (($this->recursionStack[$policyId] ?? 0) >= $this->maxRecursionDepth) {
            $this->recordFailure($policyId, $start);
            return [
                SecurityDecision::deny(
                    policyId: 'security.recursion_prevented',
                    reasonCode: 'policy_max_recursion_depth_exceeded',
                    obligations: [
                        'policy_id' => $policyId,
                        'depth' => $this->recursionStack[$policyId] ?? $this->maxRecursionDepth,
                    ],
                ),
                ControllerWorkerDisposition::TerminateOnTrustFailure,
                (int) (hrtime(true) - $start),
            ];
        }

        $timedOut = false;
        $decision = null;
        $caught = null;
        $disposition = ControllerWorkerDisposition::ContinueSafe;

        try {
            $recursionGuardBegin();
            $this->recursionStack[$policyId] = ($this->recursionStack[$policyId] ?? 0) + 1;

            \Fiber::getCurrent() !== null; // just force Fiber detection when present

            $deadlineExceeded = static function (int $s, int $timeoutNs) use (&$timedOut, &$start): bool {
                if ((hrtime(true) - $s) >= $timeoutNs) {
                    $timedOut = true;
                    return true;
                }
                return false;
            };

            $decision = $this->runWithDeadline(
                static function () use ($policy, $request): SecurityDecision {
                    return $policy->evaluate($request);
                },
                $start,
                $this->perPolicyTimeoutNs,
                $deadlineExceeded,
            );

            if ($timedOut) {
                $this->recordFailure($policyId, $start);
                $decision = SecurityDecision::deny(
                    policyId: 'security.deadline_exceeded',
                    reasonCode: 'policy_evaluation_timeout',
                    obligations: [
                        'policy_id' => $policyId,
                        'timeout_ns' => $this->perPolicyTimeoutNs,
                    ],
                );
                $disposition = ControllerWorkerDisposition::TerminateOnTrustFailure;
            } else {
                $this->recordSuccess($policyId);
            }
        } catch (\FiberError $fiber) {
            $caught = $fiber;
        } catch (\Error $e) {
            $caught = $e;
        } catch (\Exception $e) {
            $caught = $e;
        } catch (Throwable $t) {
            $caught = $t;
        } finally {
            if (isset($this->recursionStack[$policyId])) {
                $this->recursionStack[$policyId]--;
                if ($this->recursionStack[$policyId] <= 0) {
                    unset($this->recursionStack[$policyId]);
                }
            }
            try {
                $recursionGuardEnd();
            } catch (Throwable) {
            }
        }

        $elapsed = (int) (hrtime(true) - $start);
        if ($caught !== null) {
            $this->recordFailure($policyId, $start);
            $isFatal = $this->isFatalThrowable($caught);
            if ($isFatal) {
                $disposition = ControllerWorkerDisposition::TerminateOnTrustFailure;
            }
            $decision = SecurityDecision::deny(
                policyId: 'security.policy_evaluation_failed',
                reasonCode: $isFatal ? 'policy_evaluation_fatal_throwable' : 'policy_evaluation_exception',
                obligations: [
                    'policy_id' => $policyId,
                    'throwable_class' => $caught::class,
                    'throwable_code' => $caught->getCode(),
                ],
            );
        }

        \assert($decision instanceof SecurityDecision);

        return [
            'decision' => $decision,
            'workerDisposition' => $disposition,
            'elapsedNs' => $elapsed,
        ];
    }

    public function workerDispositionForRequest(array $perPolicyDispositions): int
    {
        $worst = ControllerWorkerDisposition::Reuse;
        foreach ($perPolicyDispositions as $d) {
            if ($d === ControllerWorkerDisposition::TerminateOnTrustFailure) {
                return ControllerWorkerDisposition::TerminateOnTrustFailure;
            }
            if ($d === ControllerWorkerDisposition::Terminate) {
                $worst = ControllerWorkerDisposition::Terminate;
            } elseif ($d === ControllerWorkerDisposition::ResetContext && $worst !== ControllerWorkerDisposition::Terminate) {
                $worst = ControllerWorkerDisposition::ResetContext;
            }
        }
        return $worst;
    }

    public function snapshot(): array
    {
        return [
            'circuit_breakers' => array_map(static fn(array $c) => [
                'failures' => $c['failures'],
                'open' => $c['open'],
            ], $this->circuitBreakers),
            'recursion_stack' => $this->recursionStack,
        ];
    }

    protected function runWithDeadline(callable $fn, int $start, int $timeoutNs, callable $checkDeadline): mixed
    {
        if ($timeoutNs <= 0) {
            return $fn();
        }

        $result = $fn();
        if ($checkDeadline($start, $timeoutNs)) {
            return null;
        }
        return $result;
    }

    private function isCircuitOpen(string $policyId, int $nowNs): bool
    {
        if (! isset($this->circuitBreakers[$policyId])) {
            return false;
        }
        $c = $this->circuitBreakers[$policyId];
        if (! $c['open']) {
            return false;
        }
        $elapsedSeconds = (int) ((($nowNs - $c['openedAt']) / 1e9));
        if ($elapsedSeconds >= $this->circuitBreakerOpenSeconds) {
            $this->circuitBreakers[$policyId] = [
                'failures' => 0,
                'open' => false,
                'openedAt' => 0,
                'rejectUntil' => 0,
            ];
            return false;
        }
        return true;
    }

    private function recordFailure(string $policyId, int $nowNs): void
    {
        if (! isset($this->circuitBreakers[$policyId])) {
            $this->circuitBreakers[$policyId] = [
                'failures' => 0,
                'open' => false,
                'openedAt' => 0,
                'rejectUntil' => 0,
            ];
        }
        $this->circuitBreakers[$policyId]['failures']++;
        if (
            $this->circuitBreakers[$policyId]['failures'] >= $this->circuitBreakerThreshold
            && $this->circuitBreakers[$policyId]['open'] === false
        ) {
            $this->circuitBreakers[$policyId]['open'] = true;
            $this->circuitBreakers[$policyId]['openedAt'] = $nowNs;
            $this->circuitBreakers[$policyId]['rejectUntil'] = $nowNs + (int) ($this->circuitBreakerOpenSeconds * 1e9);
        }
    }

    private function recordSuccess(string $policyId): void
    {
        if (! isset($this->circuitBreakers[$policyId])) {
            return;
        }
        if ($this->circuitBreakers[$policyId]['failures'] > 0) {
            $this->circuitBreakers[$policyId]['failures'] = max(0, $this->circuitBreakers[$policyId]['failures'] - 1);
        }
        if ($this->circuitBreakers[$policyId]['open'] && $this->circuitBreakers[$policyId]['failures'] === 0) {
            $this->circuitBreakers[$policyId] = [
                'failures' => 0,
                'open' => false,
                'openedAt' => 0,
                'rejectUntil' => 0,
            ];
        }
    }

    private function isFatalThrowable(Throwable $t): bool
    {
        if ($t instanceof \Error) {
            return true;
        }
        if ($t instanceof \FiberError) {
            return true;
        }
        $class = $t::class;
        $fatal = [
            \ParseError::class,
            \TypeError::class,
            \ArgumentCountError::class,
            \ArithmeticError::class,
            \DivisionByZeroError::class,
            \AssertionError::class,
        ];
        foreach ($fatal as $f) {
            if (is_a($t, $f)) {
                return true;
            }
        }
        $msg = strtolower($t->getMessage() ?: '');
        if (str_contains($msg, 'allowed memory size') || str_contains($msg, 'maximum execution time')) {
            return true;
        }
        return false;
    }
}