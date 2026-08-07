<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Budget;

final readonly class ControllerSecurityBudget
{
    public function __construct(
        public int $maxPolicyEvaluations = 64,
        public int $maxBindingDepth = 16,
        public int $maxSubrequestDepth = 8,
        public int $maxSecurityEvents = 256,
    ) {}
}
