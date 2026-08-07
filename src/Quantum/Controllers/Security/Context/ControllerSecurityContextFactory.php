<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Context;

use Quantum\Http\Request;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\Security\Budget\ControllerSecurityBudget;
use Quantum\Controllers\Security\Contracts\ControllerSecurityContextFactoryInterface;
use Quantum\Controllers\Security\Decision\SecurityDecisionCache;

final class ControllerSecurityContextFactory implements ControllerSecurityContextFactoryInterface
{
    public function __construct(
        private readonly int $defaultMaxEvaluations = 64,
    ) {}

    public function create(
        Request $request,
        ControllerExecutionContext $execution,
    ): ControllerSecurityContext {
        $principal = Principal::anonymous();
        $tenant = null;
        $authStrength = AuthenticationStrength::Anonymous;
        $attributes = new SecurityAttributes([]);
        $decisions = new SecurityDecisionCache(maxItems: $this->defaultMaxEvaluations);
        $executionId = $execution->execution?->id() ?? ('exec-' . bin2hex(random_bytes(6)));
        $budget = new ControllerSecurityBudget(maxPolicyEvaluations: $this->defaultMaxEvaluations);

        return new ControllerSecurityContext(
            principal: $principal,
            tenant: $tenant,
            authenticationStrength: $authStrength,
            attributes: $attributes,
            decisions: $decisions,
            executionId: $executionId,
            budget: $budget,
            version: 1,
        );
    }
}
