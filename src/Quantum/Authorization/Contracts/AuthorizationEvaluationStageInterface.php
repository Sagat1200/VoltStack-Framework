<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionResult;

interface AuthorizationEvaluationStageInterface
{
    public function name(): string;

    /**
     * @return list<DecisionResult>
     */
    public function evaluate(AuthorizationRequest $request): array;
}
