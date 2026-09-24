<?php

declare(strict_types=1);

namespace Quantum\Authorization\Decision;

final class DecisionManager
{
    /**
     * @param list<DecisionResult> $results
     */
    public function finalize(array $results): DecisionResult
    {
        if ($results === []) {
            return DecisionResult::deny(reasonCode: 'deny_by_default');
        }

        $allowed = null;

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
            }
        }

        return $allowed ?? DecisionResult::deny(reasonCode: 'deny_by_default');
    }
}
