<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyRegistryInterface;
use Quantum\Controllers\Security\Exceptions\SecurityInfrastructureFailureException;

final class ControllerSecurityPolicyRegistry implements ControllerSecurityPolicyRegistryInterface
{
    /** @var array<string, ControllerSecurityPolicyInterface> */
    private array $policies = [];

    private bool $isFrozen = false;

    public function register(ControllerSecurityPolicyInterface $policy): void
    {
        if ($this->isFrozen) {
            throw new SecurityInfrastructureFailureException(sprintf(
                'Cannot register security policy [%s]: registry is frozen.',
                $policy->id(),
            ));
        }
        $this->policies[$policy->id()] = $policy;
    }

    public function resolve(string $policyId): ControllerSecurityPolicyInterface
    {
        if (! isset($this->policies[$policyId])) {
            throw new SecurityInfrastructureFailureException(sprintf(
                'Security policy [%s] not registered.',
                $policyId,
            ));
        }

        return $this->policies[$policyId];
    }

    /** @return iterable<ControllerSecurityPolicyInterface> */
    public function all(): iterable
    {
        return array_values($this->policies);
    }

    public function freeze(): void
    {
        $this->isFrozen = true;
    }

    public function frozen(): bool
    {
        return $this->isFrozen;
    }

    public function count(): int
    {
        return count($this->policies);
    }
}
