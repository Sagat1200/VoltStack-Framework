<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Context;

use Quantum\Controllers\Security\Budget\ControllerSecurityBudget;
use Quantum\Controllers\Security\Contracts\PrincipalInterface;
use Quantum\Controllers\Security\Contracts\SecurityDecisionCacheInterface;

final readonly class ControllerSecurityContext
{
    public function __construct(
        public PrincipalInterface $principal,
        public ?TenantIdentity $tenant,
        public AuthenticationStrength $authenticationStrength,
        public SecurityAttributes $attributes,
        public SecurityDecisionCacheInterface $decisions,
        public string $executionId,
        public ControllerSecurityBudget $budget,
        public int $version = 1,
    ) {}

    public function isAnonymous(): bool
    {
        return ! $this->principal->authenticated() || $this->principal->type() === PrincipalType::Anonymous;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null && $this->tenant->verified;
    }
}
