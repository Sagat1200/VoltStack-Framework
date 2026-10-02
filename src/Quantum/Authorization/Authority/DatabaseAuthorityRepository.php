<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

use PDO;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Database\Contracts\DatabaseInterface;

/**
 * AuthorityRepository persistente sobre la capa DBAL/Database del framework.
 *
 * Este repositorio es read-only para el pipeline de autorización. Resuelve
 * grants desde tres tablas simples:
 * - role grants por principal/scope,
 * - permission grants directas por principal/scope,
 * - catálogo role -> permission.
 *
 * La resolución efectiva preserva la misma semántica observable que
 * InMemoryAuthorityRepository: grants directos del scope actual + ancestros.
 */
final class DatabaseAuthorityRepository implements AuthorityRepositoryInterface
{
    public const DEFAULT_ROLE_GRANTS_TABLE = 'authorization_role_grants';
    public const DEFAULT_PERMISSION_GRANTS_TABLE = 'authorization_permission_grants';
    public const DEFAULT_ROLE_PERMISSIONS_TABLE = 'authorization_role_permissions';

    /**
     * @var array{role_grants:string,permission_grants:string,role_permissions:string}
     */
    private array $tables;

    /**
     * @param array{role_grants?:string,permission_grants?:string,role_permissions?:string} $tables
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ?string $connectionName = null,
        array $tables = [],
    ) {
        $this->tables = [
            'role_grants' => $this->normalizeTableName($tables['role_grants'] ?? self::DEFAULT_ROLE_GRANTS_TABLE),
            'permission_grants' => $this->normalizeTableName($tables['permission_grants'] ?? self::DEFAULT_PERMISSION_GRANTS_TABLE),
            'role_permissions' => $this->normalizeTableName($tables['role_permissions'] ?? self::DEFAULT_ROLE_PERMISSIONS_TABLE),
        ];
    }

    public function rolesForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $roleNames = $this->fetchRoleNames($principalId, $this->normalizeScopeName($scope));
        $permissionsByRole = $this->fetchPermissionsForRoles($roleNames);
        $roles = [];

        foreach ($roleNames as $roleName) {
            $roles[] = new Role($roleName, $permissionsByRole[$roleName] ?? []);
        }

        return $this->deduplicateRoles($roles);
    }

    public function directPermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        return $this->fetchDirectPermissions($principalId, $this->normalizeScopeName($scope));
    }

    public function effectivePermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $current = $scope instanceof Scope ? $scope : new Scope($scope);
        $visited = [];
        $collectedPermissions = [];

        while ($current !== null && ! in_array((string) $current, $visited, true)) {
            $scopeName = (string) $current;
            $visited[] = $scopeName;

            foreach ($this->fetchDirectPermissions($principalId, $scopeName) as $permission) {
                $collectedPermissions[$permission->name] = $permission;
            }

            $roleNames = $this->fetchRoleNames($principalId, $scopeName);
            foreach ($this->fetchPermissionsForRoles($roleNames) as $permissions) {
                foreach ($permissions as $permission) {
                    $collectedPermissions[$permission->name] = $permission;
                }
            }

            $current = $current->parent();
        }

        return array_values($collectedPermissions);
    }

    public function scopesForPrincipal(string $principalId): array
    {
        $scopeNames = array_unique(array_merge(
            $this->fetchScopeNames($this->tables['role_grants'], $principalId),
            $this->fetchScopeNames($this->tables['permission_grants'], $principalId),
        ));

        sort($scopeNames);

        return array_map(static fn (string $scopeName): Scope => new Scope($scopeName), $scopeNames);
    }

    public function hasPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        $permissionObject = $permission instanceof Permission ? $permission : Permission::from($permission);

        foreach ($this->effectivePermissionsForPrincipal($principalId, $scope) as $effectivePermission) {
            if ($effectivePermission->equals($permissionObject) || $effectivePermission->matches($permissionObject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function fetchRoleNames(string $principalId, string $scopeName): array
    {
        $statement = $this->pdo()->prepare(sprintf(
            'SELECT role_name FROM %s WHERE principal_id = :principal_id AND scope = :scope ORDER BY role_name ASC',
            $this->tables['role_grants'],
        ));
        $statement->execute([
            ':principal_id' => $principalId,
            ':scope' => $scopeName,
        ]);

        $rows = $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_values(array_unique(array_map('strval', $rows)));
    }

    /**
     * @return list<Permission>
     */
    private function fetchDirectPermissions(string $principalId, string $scopeName): array
    {
        $statement = $this->pdo()->prepare(sprintf(
            'SELECT permission_name FROM %s WHERE principal_id = :principal_id AND scope = :scope ORDER BY permission_name ASC',
            $this->tables['permission_grants'],
        ));
        $statement->execute([
            ':principal_id' => $principalId,
            ':scope' => $scopeName,
        ]);

        $rows = $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $permissions = [];

        foreach ($rows as $permissionName) {
            $permission = Permission::from((string) $permissionName);
            $permissions[$permission->name] = $permission;
        }

        return array_values($permissions);
    }

    /**
     * @param list<string> $roleNames
     * @return array<string, list<Permission>>
     */
    private function fetchPermissionsForRoles(array $roleNames): array
    {
        if ($roleNames === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($roleNames) as $index => $roleName) {
            $placeholder = ':role_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $roleName;
        }

        $statement = $this->pdo()->prepare(sprintf(
            'SELECT role_name, permission_name FROM %s WHERE role_name IN (%s) ORDER BY role_name ASC, permission_name ASC',
            $this->tables['role_permissions'],
            implode(', ', $placeholders),
        ));
        $statement->execute($params);

        /** @var list<array{role_name:string, permission_name:string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $permissionsByRole = [];

        foreach ($rows as $row) {
            $roleName = (string) ($row['role_name'] ?? '');
            $permissionName = (string) ($row['permission_name'] ?? '');

            if ($roleName === '' || $permissionName === '') {
                continue;
            }

            $permission = Permission::from($permissionName);
            $permissionsByRole[$roleName][$permission->name] = $permission;
        }

        foreach ($permissionsByRole as $roleName => $permissions) {
            $permissionsByRole[$roleName] = array_values($permissions);
        }

        return $permissionsByRole;
    }

    /**
     * @return list<string>
     */
    private function fetchScopeNames(string $table, string $principalId): array
    {
        $statement = $this->pdo()->prepare(sprintf(
            'SELECT scope FROM %s WHERE principal_id = :principal_id ORDER BY scope ASC',
            $table,
        ));
        $statement->execute([
            ':principal_id' => $principalId,
        ]);

        $rows = $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_values(array_unique(array_map('strval', $rows)));
    }

    private function pdo(): PDO
    {
        return $this->database->connection($this->connectionName)->pdo();
    }

    private function normalizeScopeName(Scope|string $scope): string
    {
        return (string) ($scope instanceof Scope ? $scope : new Scope($scope));
    }

    private function normalizeTableName(string $table): string
    {
        $normalized = trim($table);

        if ($normalized === '' || ! preg_match('/^[A-Za-z0-9_.]+$/', $normalized)) {
            throw new \InvalidArgumentException('Invalid authority table name.');
        }

        return $normalized;
    }

    /**
     * @param list<Role> $roles
     * @return list<Role>
     */
    private function deduplicateRoles(array $roles): array
    {
        $deduplicated = [];

        foreach ($roles as $role) {
            $deduplicated[$role->name] = $role;
        }

        return array_values($deduplicated);
    }
}
