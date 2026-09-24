<?php

declare(strict_types=1);

namespace Quantum\Authorization\Decision;

final class DecisionManager
{
    public function __construct(
        private readonly string $defaultStrategy = 'deny',
    ) {}

    /**
     * @param list<DecisionResult> $results
     */
    public function finalize(array $results): DecisionResult
    {
        if ($results === []) {
            return $this->defaultDecision('no_evaluators_resolved');
        }

        $allowed = null;
        $abstained = false;

        foreach ($results as $result) {
            if ($result->isFailure()) {
                return $result;
            }

            if ($result->isChallenge()) {
                return $result;
            }

            if ($result->isDenied()) {
                return $result;
            }

            if ($result->isAllowed()) {
                $allowed = $result;
                continue;
            }

            if ($result->isAbstain()) {
                $abstained = true;
            }
        }

        if ($allowed !== null) {
            return $allowed;
        }

        return $this->defaultDecision($abstained ? 'all_evaluators_abstained' : 'no_explicit_decision');
    }

    private function defaultDecision(string $suffix): DecisionResult
    {
        if ($this->defaultStrategy === 'allow') {
            return DecisionResult::allow(
                source: 'authorization.default_strategy',
                reasonCode: $suffix === 'no_evaluators_resolved'
                    ? 'allow_by_default'
                    : $suffix . '_allow_by_default',
            );
        }

        return DecisionResult::deny(
            source: 'authorization.default_strategy',
            reasonCode: in_array($suffix, ['no_evaluators_resolved', 'no_explicit_decision'], true)
                ? 'deny_by_default'
                : $suffix . '_deny_by_default',
        );
    }
}
