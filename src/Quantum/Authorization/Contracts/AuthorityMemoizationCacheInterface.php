<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Scope;

/**
 * Cache request-scoped para cómputos derivados de AuthorityRepository.
 *
 * El TTL es implícito: cuando la instancia scoped es destruida al terminar
 * la request, el cache desaparece. NUNCA usar como singleton persistente
 * en runtimes long-running sin política de invalidación explícita.
 */
interface AuthorityMemoizationCacheInterface
{
    /**
     * Devuelve permissions cacheadas para (principalId, scope) o ejecuta
     * $compute una sola vez y almacena el resultado.
     *
     * @return list<Permission>
     */
    public function rememberEffectivePermissions(
        string $principalId,
        Scope|string $scope = Scope::GLOBAL,
        callable $compute,
    ): array;

    public function has(string $principalId, Scope|string $scope = Scope::GLOBAL): bool;

    public function clear(string $principalId, Scope|string $scope = Scope::GLOBAL): void;

    /**
     * Limpia TODAS las entradas (util para tests y cambios de grants
     * en memoria dentro de la misma request).
     */
    public function clearAll(): void;
}
