<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionResult;

interface AuthorizationPlannerInterface
{
    /**
     * @return list<DecisionResult>
     */
    public function plan(AuthorizationRequest $request): array;

    public function evaluate(AuthorizationRequest $request): DecisionResult;
}
