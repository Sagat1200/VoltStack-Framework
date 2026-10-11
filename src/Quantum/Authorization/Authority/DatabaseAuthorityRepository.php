<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

use PDO;
use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\DelegationAdministrationInterface;
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
final class DatabaseAuthorityRepository implements AuthorityAdministrationInterface, AuthorityRepositoryInterface, DelegationAdministrationInterface
{
    public const DEFAULT_ROLE_GRANTS_TABLE = 'authorization_role_grants';
    public const DEFAULT_PERMISSION_GRANTS_TABLE = 'authorization_permission_grants';
    public const DEFAULT_ROLE_PERMISSIONS_TABLE = 'authorization_role_permissions';
    public const DEFAULT_DELEGATION_GRANTS_TABLE = 'authorization_delegation_grants';

    /**
     * @var array{role_grants:string,permission_grants:string,role_permissions:string,delegation_grants:string}
     */
    private array $tables;

    /**
     * @param array{role_grants?:string,permission_grants?:string,role_permissions?:string,delegation_grants?:string} $tables
     *
     * Shape recomendada para la tabla delegation_grants (por defecto authorization_delegation_grants):
     *   trustee_id      VARCHAR(128) NOT NULL
     *   grantor_id      VARCHAR(128) NOT NULL
     *   scope           VARCHAR(128) NOT NULL
     *   grant_type      VARCHAR(16)  NOT NULL  -- 'role' | 'permission'
     *   grant_value     VARCHAR(255) NOT NULL
     *   granted_at      DATETIME(3) NULL
     * UNIQUE KEY uniq_delegation (trustee_id, grantor_id, scope, grant_type, grant_value)
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ?string $connectionName = null,
        array $tables = [],
        private readonly ?AuthorizationConsistencyInterface $consistency = null,
    ) {
        $this->tables = [
            'role_grants' => $this->normalizeTableName($tables['role_grants'] ?? self::DEFAULT_ROLE_GRANTS_TABLE),
            'permission_grants' => $this->normalizeTableName($tables['permission_grants'] ?? self::DEFAULT_PERMISSION_GRANTS_TABLE),
            'role_permissions' => $this->normalizeTableName($tables['role_permissions'] ?? self::DEFAULT_ROLE_PERMISSIONS_TABLE),
            'delegation_grants' => $this->normalizeTableName($tables['delegation_grants'] ?? self::DEFAULT_DELEGATION_GRANTS_TABLE),
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

    public function listGrants(array $filters = []): array
    {
        $principalFilter = $this->normalizeOptionalString($filters['principal_id'] ?? null);
        $scopeFilter = $this->normalizeOptionalScopeName($filters['scope'] ?? null);
        $typeFilter = strtolower(trim((string) ($filters['type'] ?? 'all')));
        $valueFilter = $this->normalizeOptionalString($filters['value'] ?? null);
        $rows = [];

        if ($typeFilter === 'all' || $typeFilter === 'role') {
            $rows = [...$rows, ...$this->fetchGrantRows(
                table: $this->tables['role_grants'],
                valueColumn: 'role_name',
                type: 'role',
                principalFilter: $principalFilter,
                scopeFilter: $scopeFilter,
                valueFilter: $valueFilter,
            )];
        }

        if ($typeFilter === 'all' || $typeFilter === 'permission') {
            $rows = [...$rows, ...$this->fetchGrantRows(
                table: $this->tables['permission_grants'],
                valueColumn: 'permission_name',
                type: 'permission',
                principalFilter: $principalFilter,
                scopeFilter: $scopeFilter,
                valueFilter: $valueFilter,
            )];
        }

        usort($rows, static function (array $left, array $right): int {
            $leftKey = $left['principal_id'] . '|' . $left['scope'] . '|' . $left['type'] . '|' . $left['value'];
            $rightKey = $right['principal_id'] . '|' . $right['scope'] . '|' . $right['type'] . '|' . $right['value'];

            return $leftKey <=> $rightKey;
        });

        return $rows;
    }

    public function grantRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool
    {
        $roleName = ($role instanceof Role ? $role : new Role($role))->name;
        $scopeName = $this->normalizeScopeName($scope);

        if ($this->grantExists($this->tables['role_grants'], 'role_name', $principalId, $scopeName, $roleName)) {
            return false;
        }

        $statement = $this->pdo()->prepare(sprintf(
            'INSERT INTO %s (principal_id, scope, role_name) VALUES (:principal_id, :scope, :value)',
            $this->tables['role_grants'],
        ));
        $statement->execute([
            ':principal_id' => $principalId,
            ':scope' => $scopeName,
            ':value' => $roleName,
        ]);

        $this->consistency?->invalidateAuthority(trim($principalId), $scopeName, 'authority.grant_role');

        return true;
    }

    public function grantPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        $permissionName = ($permission instanceof Permission ? $permission : Permission::from($permission))->name;
        $scopeName = $this->normalizeScopeName($scope);

        if ($this->grantExists($this->tables['permission_grants'], 'permission_name', $principalId, $scopeName, $permissionName)) {
            return false;
        }

        $statement = $this->pdo()->prepare(sprintf(
            'INSERT INTO %s (principal_id, scope, permission_name) VALUES (:principal_id, :scope, :value)',
            $this->tables['permission_grants'],
        ));
        $statement->execute([
            ':principal_id' => $principalId,
            ':scope' => $scopeName,
            ':value' => $permissionName,
        ]);

        $this->consistency?->invalidateAuthority(trim($principalId), $scopeName, 'authority.grant_permission');

        return true;
    }

    public function revokeRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool
    {
        $roleName = ($role instanceof Role ? $role : new Role($role))->name;
        $scopeName = $this->normalizeScopeName($scope);
        $statement = $this->pdo()->prepare(sprintf(
            'DELETE FROM %s WHERE principal_id = :principal_id AND scope = :scope AND role_name = :value',
            $this->tables['role_grants'],
        ));
        $statement->execute([
            ':principal_id' => $principalId,
            ':scope' => $scopeName,
            ':value' => $roleName,
        ]);
        $deleted = $statement->rowCount() > 0;

        if ($deleted) {
            $this->consistency?->invalidateAuthority(trim($principalId), $scopeName, 'authority.revoke_role');
        }

        return $deleted;
    }

    public function revokePermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        $permissionName = ($permission instanceof Permission ? $permission : Permission::from($permission))->name;
        $scopeName = $this->normalizeScopeName($scope);
        $statement = $this->pdo()->prepare(sprintf(
            'DELETE FROM %s WHERE principal_id = :principal_id AND scope = :scope AND permission_name = :value',
            $this->tables['permission_grants'],
        ));
        $statement->execute([
            ':principal_id' => $principalId,
            ':scope' => $scopeName,
            ':value' => $permissionName,
        ]);
        $deleted = $statement->rowCount() > 0;

        if ($deleted) {
            $this->consistency?->invalidateAuthority(trim($principalId), $scopeName, 'authority.revoke_permission');
        }

        return $deleted;
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

    /**
     * @return list<array{principal_id:string,scope:string,type:string,value:string}>
     */
    private function fetchGrantRows(
        string $table,
        string $valueColumn,
        string $type,
        ?string $principalFilter,
        ?string $scopeFilter,
        ?string $valueFilter,
    ): array {
        $conditions = [];
        $params = [];

        if ($principalFilter !== null) {
            $conditions[] = 'principal_id = :principal_id';
            $params[':principal_id'] = $principalFilter;
        }

        if ($scopeFilter !== null) {
            $conditions[] = 'scope = :scope';
            $params[':scope'] = $scopeFilter;
        }

        if ($valueFilter !== null) {
            $conditions[] = sprintf('%s = :value', $valueColumn);
            $params[':value'] = $valueFilter;
        }

        $statement = $this->pdo()->prepare(sprintf(
            'SELECT principal_id, scope, %s AS value FROM %s%s ORDER BY principal_id ASC, scope ASC, value ASC',
            $valueColumn,
            $table,
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
        ));
        $statement->execute($params);

        /** @var list<array{principal_id:string,scope:string,value:string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $row): array => [
            'principal_id' => (string) ($row['principal_id'] ?? ''),
            'scope' => (string) ($row['scope'] ?? ''),
            'type' => $type,
            'value' => (string) ($row['value'] ?? ''),
        ], $rows);
    }

    private function grantExists(string $table, string $valueColumn, string $principalId, string $scopeName, string $value): bool
    {
        $statement = $this->pdo()->prepare(sprintf(
            'SELECT COUNT(*) FROM %s WHERE principal_id = :principal_id AND scope = :scope AND %s = :value',
            $table,
            $valueColumn,
        ));
        $statement->execute([
            ':principal_id' => $principalId,
            ':scope' => $scopeName,
            ':value' => $value,
        ]);

        return ((int) $statement->fetchColumn()) > 0;
    }

    private function pdo(): PDO
    {
        return $this->database->connection($this->connectionName)->pdo();
    }

    private function normalizeScopeName(Scope|string $scope): string
    {
        return (string) ($scope instanceof Scope ? $scope : new Scope($scope));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeOptionalScopeName(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) ($value instanceof Scope ? $value : new Scope((string) $value));
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

    public function listDelegations(array $filters = []): array
    {
        $trusteeFilter = $this->normalizeOptionalString($filters['trustee_id'] ?? null);
        $grantorFilter = $this->normalizeOptionalString($filters['grantor_id'] ?? null);
        $scopeFilter = $this->normalizeOptionalScopeName($filters['scope'] ?? null);
        $typeFilter = strtolower(trim((string) ($filters['type'] ?? 'all')));
        $valueFilter = $this->normalizeOptionalString($filters['value'] ?? null);
        $includeExpired = (bool) ($filters['include_expired'] ?? false);
        $includeRevoked = (bool) ($filters['include_revoked'] ?? false);

        $conditions = [];
        $params = [];

        if (! $includeRevoked) {
            $conditions[] = 'revoked_at IS NULL';
        }

        if ($trusteeFilter !== null) {
            $conditions[] = 'trustee_id = :trustee_id';
            $params[':trustee_id'] = $trusteeFilter;
        }

        if ($grantorFilter !== null) {
            $conditions[] = 'grantor_id = :grantor_id';
            $params[':grantor_id'] = $grantorFilter;
        }

        if ($scopeFilter !== null) {
            $conditions[] = 'scope = :scope';
            $params[':scope'] = $scopeFilter;
        }

        if ($typeFilter !== 'all') {
            $conditions[] = 'grant_type = :grant_type';
            $params[':grant_type'] = $typeFilter;
        }

        if ($valueFilter !== null) {
            $conditions[] = 'grant_value = :grant_value';
            $params[':grant_value'] = $valueFilter;
        }

        if (! $includeExpired) {
            $conditions[] = '(expires_at IS NULL OR expires_at > :now)';
            $params[':now'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        }

        $statement = $this->pdo()->prepare(sprintf(
            'SELECT trustee_id, grantor_id, scope, grant_type AS type, grant_value AS value, granted_at, expires_at, revoked_at FROM %s%s ORDER BY trustee_id ASC, grantor_id ASC, scope ASC, type ASC, value ASC',
            $this->tables['delegation_grants'],
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
        ));
        $statement->execute($params);

        /** @var list<array{trustee_id:mixed,grantor_id:mixed,scope:mixed,type:mixed,value:mixed,granted_at:mixed,expires_at:mixed,revoked_at:mixed}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $grantedAt = $row['granted_at'] ?? null;
            if ($grantedAt instanceof \DateTimeInterface) {
                $grantedAt = $grantedAt->format(\DateTimeInterface::ATOM);
            } elseif (is_string($grantedAt) && trim($grantedAt) !== '') {
                try {
                    $grantedAt = (new \DateTimeImmutable($grantedAt))->format(\DateTimeInterface::ATOM);
                } catch (\Throwable) {
                    $grantedAt = null;
                }
            } else {
                $grantedAt = null;
            }

            $expiresAt = $row['expires_at'] ?? null;
            if ($expiresAt instanceof \DateTimeInterface) {
                $expiresAt = $expiresAt->format(\DateTimeInterface::ATOM);
            } elseif (is_string($expiresAt) && trim($expiresAt) !== '') {
                try {
                    $expiresAt = (new \DateTimeImmutable($expiresAt))->format(\DateTimeInterface::ATOM);
                } catch (\Throwable) {
                    $expiresAt = null;
                }
            } else {
                $expiresAt = null;
            }

            return [
                'trustee_id' => (string) ($row['trustee_id'] ?? ''),
                'grantor_id' => (string) ($row['grantor_id'] ?? ''),
                'scope' => (string) ($row['scope'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'value' => (string) ($row['value'] ?? ''),
                'granted_at' => $grantedAt,
                'expires_at' => $expiresAt,
            ];
        }, $rows);
    }

    public function grantDelegation(
        string $trusteeId,
        string $grantorId,
        Role|Permission $grant,
        Scope|string $scope = Scope::GLOBAL,
        ?string $expiresAt = null,
    ): bool {
        $trusteeId = trim($trusteeId);
        $grantorId = trim($grantorId);

        if ($trusteeId === '' || $grantorId === '') {
            return false;
        }

        $scopeName = $this->normalizeScopeName($scope);
        $grantType = $grant instanceof Role ? 'role' : 'permission';
        $grantValue = $grant->name;

        $existingRevoked = $this->findDelegationRow($trusteeId, $grantorId, $scopeName, $grantType, $grantValue);

        if ($existingRevoked !== null) {
            if ($existingRevoked['revoked_at'] !== null) {
                // Reactivacion de un grant revocado.
                $normalizedExpiresAt = $this->normalizeExpiresAt($expiresAt);
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

                try {
                    $statement = $this->pdo()->prepare(sprintf(
                        'UPDATE %s SET revoked_at = NULL, granted_at = :granted_at, expires_at = :expires_at WHERE trustee_id = :trustee_id AND grantor_id = :grantor_id AND scope = :scope AND grant_type = :grant_type AND grant_value = :grant_value',
                        $this->tables['delegation_grants'],
                    ));
                    $statement->execute([
                        ':trustee_id' => $trusteeId,
                        ':grantor_id' => $grantorId,
                        ':scope' => $scopeName,
                        ':grant_type' => $grantType,
                        ':grant_value' => $grantValue,
                        ':granted_at' => $now,
                        ':expires_at' => $normalizedExpiresAt,
                    ]);
                } catch (\Throwable) {
                    return false;
                }

                $this->consistency?->invalidateAuthority($trusteeId, $scopeName, 'delegation.reactivate');

                return true;
            }

            return false;
        }

        $normalizedExpiresAt = $this->normalizeExpiresAt($expiresAt);

        try {
            $statement = $this->pdo()->prepare(sprintf(
                'INSERT INTO %s (trustee_id, grantor_id, scope, grant_type, grant_value, granted_at, expires_at, revoked_at) VALUES (:trustee_id, :grantor_id, :scope, :grant_type, :grant_value, :granted_at, :expires_at, NULL)',
                $this->tables['delegation_grants'],
            ));
            $statement->execute([
                ':trustee_id' => $trusteeId,
                ':grantor_id' => $grantorId,
                ':scope' => $scopeName,
                ':grant_type' => $grantType,
                ':grant_value' => $grantValue,
                ':granted_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                ':expires_at' => $normalizedExpiresAt,
            ]);
        } catch (\Throwable) {
            return false;
        }

        $this->consistency?->invalidateAuthority($trusteeId, $scopeName, 'delegation.grant');

        return true;
    }

    public function revokeDelegation(
        string $trusteeId,
        string $grantorId,
        Role|Permission $grant,
        Scope|string $scope = Scope::GLOBAL,
    ): bool {
        $trusteeId = trim($trusteeId);
        $grantorId = trim($grantorId);

        if ($trusteeId === '' || $grantorId === '') {
            return false;
        }

        $scopeName = $this->normalizeScopeName($scope);
        $grantType = $grant instanceof Role ? 'role' : 'permission';
        $grantValue = $grant->name;

        try {
            $statement = $this->pdo()->prepare(sprintf(
                'UPDATE %s SET revoked_at = :revoked_at WHERE trustee_id = :trustee_id AND grantor_id = :grantor_id AND scope = :scope AND grant_type = :grant_type AND grant_value = :grant_value AND revoked_at IS NULL',
                $this->tables['delegation_grants'],
            ));
            $statement->execute([
                ':trustee_id' => $trusteeId,
                ':grantor_id' => $grantorId,
                ':scope' => $scopeName,
                ':grant_type' => $grantType,
                ':grant_value' => $grantValue,
                ':revoked_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]);
        } catch (\Throwable) {
            return false;
        }

        $deleted = $statement->rowCount() > 0;

        if ($deleted) {
            $this->consistency?->invalidateAuthority($trusteeId, $scopeName, 'delegation.revoke');
        }

        return $deleted;
    }

    public function revokeAllDelegations(
        ?string $trusteeId = null,
        ?string $grantorId = null,
        Scope|string|null $scope = null,
    ): int {
        $trusteeId = is_string($trusteeId) ? trim($trusteeId) : null;
        $grantorId = is_string($grantorId) ? trim($grantorId) : null;

        if (($trusteeId === null || $trusteeId === '') && ($grantorId === null || $grantorId === '')) {
            return 0;
        }

        $scopeName = $scope === null ? null : $this->normalizeScopeName($scope);

        $conditions = [];
        $params = [];

        if ($trusteeId !== null && $trusteeId !== '') {
            $conditions[] = 'trustee_id = :trustee_id';
            $params[':trustee_id'] = $trusteeId;
        }

        if ($grantorId !== null && $grantorId !== '') {
            $conditions[] = 'grantor_id = :grantor_id';
            $params[':grantor_id'] = $grantorId;
        }

        if ($scopeName !== null) {
            $conditions[] = 'scope = :scope';
            $params[':scope'] = $scopeName;
        }

        $conditions[] = 'revoked_at IS NULL';

        try {
            $statement = $this->pdo()->prepare(sprintf(
                'UPDATE %s SET revoked_at = :revoked_at%s',
                $this->tables['delegation_grants'],
                $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            ));
            $params[':revoked_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $statement->execute($params);
        } catch (\Throwable) {
            return 0;
        }

        $removed = $statement->rowCount();

        if ($removed > 0) {
            $bumpPrincipal = $trusteeId ?? $grantorId;
            $this->consistency?->invalidateAuthority($bumpPrincipal, $scopeName ?? Scope::GLOBAL, 'delegation.revoke_all');
        }

        return $removed;
    }

    public function revokeDelegationsByPolicy(callable $predicate, ?string $reason = null): int
    {
        $statement = $this->pdo()->prepare(sprintf(
            'SELECT trustee_id, grantor_id, scope, grant_type AS type, grant_value AS value, granted_at, expires_at, revoked_at FROM %s WHERE revoked_at IS NULL',
            $this->tables['delegation_grants'],
        ));
        $statement->execute();

        /** @var list<array{trustee_id:mixed,grantor_id:mixed,scope:mixed,type:mixed,value:mixed,granted_at:mixed,expires_at:mixed,revoked_at:mixed}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $revoked = 0;

        foreach ($rows as $row) {
            $grant = [
                'trustee_id' => (string) ($row['trustee_id'] ?? ''),
                'grantor_id' => (string) ($row['grantor_id'] ?? ''),
                'scope' => (string) ($row['scope'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'value' => (string) ($row['value'] ?? ''),
                'granted_at' => $this->formatDateTimeValue($row['granted_at'] ?? null),
                'expires_at' => $this->formatDateTimeValue($row['expires_at'] ?? null),
                'revoked_at' => $this->formatDateTimeValue($row['revoked_at'] ?? null),
            ];

            if (! $predicate($grant)) {
                continue;
            }

            try {
                $update = $this->pdo()->prepare(sprintf(
                    'UPDATE %s SET revoked_at = :revoked_at WHERE trustee_id = :trustee_id AND grantor_id = :grantor_id AND scope = :scope AND grant_type = :grant_type AND grant_value = :grant_value AND revoked_at IS NULL',
                    $this->tables['delegation_grants'],
                ));
                $update->execute([
                    ':revoked_at' => $now,
                    ':trustee_id' => $grant['trustee_id'],
                    ':grantor_id' => $grant['grantor_id'],
                    ':scope' => $grant['scope'],
                    ':grant_type' => $grant['type'],
                    ':grant_value' => $grant['value'],
                ]);

                if ($update->rowCount() > 0) {
                    $revoked++;
                }
            } catch (\Throwable) {
                // Ignore individual row failures; continue revoking the rest.
            }
        }

        if ($revoked > 0) {
            $bumpReason = $reason !== null && trim($reason) !== '' ? trim($reason) : 'delegation.revoke_policy';
            $this->consistency?->invalidateAuthority(null, null, $bumpReason);
        }

        return $revoked;
    }

    private function formatDateTimeValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function normalizeExpiresAt(?string $expiresAt): ?string
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return null;
        }

        $trimmed = trim($expiresAt);

        $timestamp = strtotime($trimmed);
        if ($timestamp !== false) {
            return (new \DateTimeImmutable('@' . $timestamp, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        }

        try {
            $dt = new \DateTimeImmutable($trimmed, new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s.u');
        } catch (\Throwable) {
            return null;
        }
    }

    private function findDelegationRow(
        string $trusteeId,
        string $grantorId,
        string $scopeName,
        string $grantType,
        string $grantValue,
    ): ?array {
        $statement = $this->pdo()->prepare(sprintf(
            'SELECT revoked_at FROM %s WHERE trustee_id = :trustee_id AND grantor_id = :grantor_id AND scope = :scope AND grant_type = :grant_type AND grant_value = :grant_value LIMIT 1',
            $this->tables['delegation_grants'],
        ));
        $statement->execute([
            ':trustee_id' => $trusteeId,
            ':grantor_id' => $grantorId,
            ':scope' => $scopeName,
            ':grant_type' => $grantType,
            ':grant_value' => $grantValue,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
