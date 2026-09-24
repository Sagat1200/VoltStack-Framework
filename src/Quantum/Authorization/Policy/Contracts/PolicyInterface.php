<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy\Contracts;

use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionResult;

interface PolicyInterface
{
    public function evaluate(AuthorizationRequest $request): DecisionResult;
}
