<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

use Quantum\Authorization\Contracts\AuthorityMemoizationCacheInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;

/**
 * Decorator que memoiza `effectivePermissionsForPrincipal` sobre un
 * AuthorityRepositoryInterface interno. El resto de métodos se delegan
 * directamente para mantener la misma semántica observable.
 *
 * Tiene el beneficio indirecto de memoizar también la consulta de
 * `hasPermission`, porque internamente invoca effectivePermissionsForPrincipal
 * sobre el repositorio inner cuando no hay caché.
 */
final class CachedAuthorityRepository implements AuthorityRepositoryInterface
{
    public function __construct(
        private readonly AuthorityRepositoryInterface     $inner,
        private readonly AuthorityMemoizationCacheInterface $cache,
    ) {}

    /**
     * @return list<Role>
     */
    public function rolesForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        return $this->inner->rolesForPrincipal($principalId, $scope);
    }

    /**
     * @return list<Permission>
     */
    public function directPermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        return $this->inner->directPermissionsForPrincipal($principalId, $scope);
    }

    /**
     * @return list<Permission>
     */
    public function effectivePermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $scopeObject = $scope instanceof Scope ? $scope : new Scope($scope);

        return $this->cache->rememberEffectivePermissions(
            $principalId,
            $scopeObject,
            fn(): array => $this->inner->effectivePermissionsForPrincipal($principalId, $scopeObject),
        );
    }

    /**
     * @return list<Scope>
     */
    public function scopesForPrincipal(string $principalId): array
    {
        return $this->inner->scopesForPrincipal($principalId);
    }

    public function hasPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        return $this->inner->hasPermission($principalId, $permission, $scope);
    }
}
