<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

interface ControllerSecurityPolicyRegistryInterface
{
    public function register(ControllerSecurityPolicyInterface $policy): void;

    /**
     * @param class-string<ControllerSecurityPolicyInterface> $policyClass
     * @param (callable(): ControllerSecurityPolicyInterface)|null $factory Optional lazy factory. If null, class is assumed instantiable without constructor args.
     */
    public function registerClass(string $policyClass, ?callable $factory = null): void;

    public function resolve(string $policyId): ControllerSecurityPolicyInterface;

    /** @return iterable<ControllerSecurityPolicyInterface> */
    public function all(): iterable;

    public function freeze(): void;

    public function frozen(): bool;
}
