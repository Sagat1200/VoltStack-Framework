<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

interface ControllerSecurityPolicyRegistryInterface
{
    public function register(ControllerSecurityPolicyInterface $policy): void;

    public function resolve(string $policyId): ControllerSecurityPolicyInterface;

    /** @return iterable<ControllerSecurityPolicyInterface> */
    public function all(): iterable;

    public function freeze(): void;

    public function frozen(): bool;
}
