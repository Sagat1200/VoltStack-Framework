<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;

/**
 * Implementación en memoria de AuthorityRepositoryInterface.
 *
 * Los grants se indexan por principalId y scope normalizado. La herencia hacia
 * arriba en la jerarquía de scopes la resuelve `effectivePermissionsForPrincipal`
 * consultando también los ancestros del scope (menor → mayor granularidad).
 */
final class InMemoryAuthorityRepository implements AuthorityAdministrationInterface, AuthorityRepositoryInterface
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
    public function __construct(
        iterable $seedGrants = [],
        private readonly ?AuthorizationConsistencyInterface $consistency = null,
    )
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

    public function grantRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool
    {
        $scopeName = (string) ($scope instanceof Scope ? $scope : new Scope($scope));
        $roleObject = $role instanceof Role ? $role : new Role($role);

        foreach ($this->roleGrants[$principalId][$scopeName] ?? [] as $existing) {
            if ($existing->name === $roleObject->name) {
                return false;
            }
        }

        $this->roleGrants[$principalId][$scopeName][] = $roleObject;
        $this->consistency?->invalidateAuthority(trim($principalId), $scopeName);

        return true;
    }

    public function grantPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        $scopeName = (string) ($scope instanceof Scope ? $scope : new Scope($scope));
        $permissionObject = $permission instanceof Permission ? $permission : Permission::from($permission);

        foreach ($this->directPermissionGrants[$principalId][$scopeName] ?? [] as $existing) {
            if ($existing->name === $permissionObject->name) {
                return false;
            }
        }

        $this->directPermissionGrants[$principalId][$scopeName][] = $permissionObject;
        $this->consistency?->invalidateAuthority(trim($principalId), $scopeName);

        return true;
    }

    public function revokeAll(string $principalId): void
    {
        unset($this->roleGrants[$principalId], $this->directPermissionGrants[$principalId]);
        $this->consistency?->invalidateAuthority(trim($principalId));
    }

    public function revokeRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool
    {
        $scopeName = (string) ($scope instanceof Scope ? $scope : new Scope($scope));
        $roleName = ($role instanceof Role ? $role : new Role($role))->name;
        $current = $this->roleGrants[$principalId][$scopeName] ?? [];
        $remaining = array_values(array_filter(
            $current,
            static fn (Role $candidate): bool => $candidate->name !== $roleName,
        ));

        if (count($remaining) === count($current)) {
            return false;
        }

        if ($remaining === []) {
            unset($this->roleGrants[$principalId][$scopeName]);
        } else {
            $this->roleGrants[$principalId][$scopeName] = $remaining;
        }

        $this->consistency?->invalidateAuthority(trim($principalId), $scopeName);

        return true;
    }

    public function revokePermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        $scopeName = (string) ($scope instanceof Scope ? $scope : new Scope($scope));
        $permissionName = ($permission instanceof Permission ? $permission : Permission::from($permission))->name;
        $current = $this->directPermissionGrants[$principalId][$scopeName] ?? [];
        $remaining = array_values(array_filter(
            $current,
            static fn (Permission $candidate): bool => $candidate->name !== $permissionName,
        ));

        if (count($remaining) === count($current)) {
            return false;
        }

        if ($remaining === []) {
            unset($this->directPermissionGrants[$principalId][$scopeName]);
        } else {
            $this->directPermissionGrants[$principalId][$scopeName] = $remaining;
        }

        $this->consistency?->invalidateAuthority(trim($principalId), $scopeName);

        return true;
    }

    public function listGrants(array $filters = []): array
    {
        $principalFilter = $this->normalizeOptionalString($filters['principal_id'] ?? null);
        $scopeFilter = $this->normalizeOptionalScope($filters['scope'] ?? null);
        $typeFilter = strtolower(trim((string) ($filters['type'] ?? 'all')));
        $valueFilter = $this->normalizeOptionalString($filters['value'] ?? null);
        $rows = [];

        if ($typeFilter === 'all' || $typeFilter === 'role') {
            foreach ($this->roleGrants as $principalId => $grantsByScope) {
                foreach ($grantsByScope as $scopeName => $roles) {
                    foreach ($this->deduplicateRoles($roles) as $role) {
                        $rows[] = [
                            'principal_id' => $principalId,
                            'scope' => $scopeName,
                            'type' => 'role',
                            'value' => $role->name,
                        ];
                    }
                }
            }
        }

        if ($typeFilter === 'all' || $typeFilter === 'permission') {
            foreach ($this->directPermissionGrants as $principalId => $grantsByScope) {
                foreach ($grantsByScope as $scopeName => $permissions) {
                    foreach ($this->deduplicatePermissions($permissions) as $permission) {
                        $rows[] = [
                            'principal_id' => $principalId,
                            'scope' => $scopeName,
                            'type' => 'permission',
                            'value' => $permission->name,
                        ];
                    }
                }
            }
        }

        $rows = array_values(array_filter($rows, static function (array $row) use ($principalFilter, $scopeFilter, $typeFilter, $valueFilter): bool {
            if ($principalFilter !== null && $row['principal_id'] !== $principalFilter) {
                return false;
            }

            if ($scopeFilter !== null && $row['scope'] !== $scopeFilter) {
                return false;
            }

            if ($typeFilter !== 'all' && $row['type'] !== $typeFilter) {
                return false;
            }

            if ($valueFilter !== null && $row['value'] !== $valueFilter) {
                return false;
            }

            return true;
        }));

        usort($rows, static function (array $left, array $right): int {
            $leftKey = $left['principal_id'] . '|' . $left['scope'] . '|' . $left['type'] . '|' . $left['value'];
            $rightKey = $right['principal_id'] . '|' . $right['scope'] . '|' . $right['type'] . '|' . $right['value'];

            return $leftKey <=> $rightKey;
        });

        return $rows;
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

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeOptionalScope(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) ($value instanceof Scope ? $value : new Scope((string) $value));
    }
}
