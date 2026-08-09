<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

/**
 * @api Contrato público estable del Policy Registry (Bloque 15 Composition).
 *
 * Soporta policies por instancia, class-string con lazy factory, expression policies
 * (registerExpression con id = string literal) y resolución por policyId string.
 * Versión congelada hasta 2.x (incluye método registerExpression añadido en 0.15.x).
 */
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
