<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;

/**
 * Implementación en memoria de AuthorityRepositoryInterface.
 *
 * Los grants se indexan por principalId y scope normalizado. La herencia hacia
 * arriba en la jerarquía de scopes la resuelve `effectivePermissionsForPrincipal`
 * consultando también los ancestros del scope (menor → mayor granularidad).
 */
final class InMemoryAuthorityRepository implements AuthorityRepositoryInterface
{
    /**
     * @var array<string, array<string, list<Role>>> [$principalId][$scopeName] = [Role...]
     */
    private array $roleGrants = [];

    /**
     * @var array<string, array<string, list<Permission>>> [$principalId][$scopeName] = [Permission...]
     */
    private array $directPermissionGrants = [];

    /**
     * @param iterable<array{principal_id:string,scope?:string,roles?:iterable<string|Role>,permissions?:iterable<string|Permission>}> $seedGrants
     */
    public function __construct(iterable $seedGrants = [])
    {
        foreach ($seedGrants as $grant) {
            $principalId = (string) ($grant['principal_id'] ?? throw new \InvalidArgumentException('Missing principal_id in seed grant'));
            $scope = $grant['scope'] ?? Scope::GLOBAL;

            foreach (($grant['roles'] ?? []) as $role) {
                $this->grantRole($principalId, $role, $scope);
            }

            foreach (($grant['permissions'] ?? []) as $permission) {
                $this->grantPermission($principalId, $permission, $scope);
            }
        }
    }

    public function grantRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): void
    {
        $scopeName = (string) ($scope instanceof Scope ? $scope : new Scope($scope));
        $roleObject = $role instanceof Role ? $role : new Role($role);
        $this->roleGrants[$principalId][$scopeName][] = $roleObject;
    }

    public function grantPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): void
    {
        $scopeName = (string) ($scope instanceof Scope ? $scope : new Scope($scope));
        $permissionObject = $permission instanceof Permission ? $permission : Permission::from($permission);
        $this->directPermissionGrants[$principalId][$scopeName][] = $permissionObject;
    }

    public function revokeAll(string $principalId): void
    {
        unset($this->roleGrants[$principalId], $this->directPermissionGrants[$principalId]);
    }

    public function rolesForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $scopeObject = $scope instanceof Scope ? $scope : new Scope($scope);
        $scopeName = (string) $scopeObject;

        return $this->deduplicateRoles($this->roleGrants[$principalId][$scopeName] ?? []);
    }

    public function directPermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $scopeObject = $scope instanceof Scope ? $scope : new Scope($scope);
        $scopeName = (string) $scopeObject;

        return $this->deduplicatePermissions($this->directPermissionGrants[$principalId][$scopeName] ?? []);
    }

    public function effectivePermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $scopeObject = $scope instanceof Scope ? $scope : new Scope($scope);
        $current = $scopeObject;
        $visited = [];
        $collectedPermissions = [];

        while ($current !== null && ! in_array((string) $current, $visited, true)) {
            $visited[] = (string) $current;

            $roles = $this->roleGrants[$principalId][(string) $current] ?? [];
            foreach ($roles as $role) {
                foreach ($role->permissions as $permission) {
                    $collectedPermissions[$permission->name] = $permission;
                }
            }

            $direct = $this->directPermissionGrants[$principalId][(string) $current] ?? [];
            foreach ($direct as $permission) {
                $collectedPermissions[$permission->name] = $permission;
            }

            $current = $current->parent();
        }

        return array_values($collectedPermissions);
    }

    public function scopesForPrincipal(string $principalId): array
    {
        $scopeNames = array_unique(array_merge(
            array_keys($this->roleGrants[$principalId] ?? []),
            array_keys($this->directPermissionGrants[$principalId] ?? []),
        ));

        sort($scopeNames);

        return array_map(static fn (string $name): Scope => new Scope($name), $scopeNames);
    }

    public function hasPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        $permissionObject = $permission instanceof Permission ? $permission : Permission::from($permission);
        $effective = $this->effectivePermissionsForPrincipal($principalId, $scope);

        foreach ($effective as $effectivePermission) {
            if ($effectivePermission->equals($permissionObject)) {
                return true;
            }

            if ($effectivePermission->matches($permissionObject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Role> $roles
     * @return list<Role>
     */
    private function deduplicateRoles(array $roles): array
    {
        $dedup = [];

        foreach ($roles as $role) {
            $dedup[$role->name] = $role;
        }

        return array_values($dedup);
    }

    /**
     * @param list<Permission> $permissions
     * @return list<Permission>
     */
    private function deduplicatePermissions(array $permissions): array
    {
        $dedup = [];

        foreach ($permissions as $permission) {
            $dedup[$permission->name] = $permission;
        }

        return array_values($dedup);
    }
}
