<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Cache\Contracts\StoreInterface;

/**
 * Remote-cache backed authority repository. Grants are stored as JSON payloads
 * in any shared {@see StoreInterface} so multiple workers/nodes can see the
 * same grants and cooperate with the shared consistency envelope.
 *
 * This implementation intentionally stays small and focused on a concrete,
 * non-DBAL sample adapter for {@see AuthorityRepositoryInterface} +
 * {@see AuthorityAdministrationInterface} that plug-ins can register through
 * {@see \Quantum\Authorization\AuthorizationDriverRegistry::extendAuthority()}.
 */
final class RemoteCacheAuthorityRepository implements AuthorityAdministrationInterface, AuthorityRepositoryInterface
{
    public function __construct(
        private readonly StoreInterface $store,
        private readonly string $prefix = 'authorization.remote.authority',
        private readonly ?AuthorizationConsistencyInterface $consistency = null,
    ) {
        $prefix = trim($this->prefix);

        if ($prefix === '') {
            throw new \InvalidArgumentException('RemoteCacheAuthorityRepository prefix cannot be empty.');
        }
    }

    public function store(): StoreInterface
    {
        return $this->store;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function rolesForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $scopeName = $this->normalizeScopeName($scope);
        $envelope = $this->readPrincipalScopeEnvelope($principalId, $scopeName);

        return $envelope['roles'];
    }

    public function directPermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $scopeName = $this->normalizeScopeName($scope);
        $envelope = $this->readPrincipalScopeEnvelope($principalId, $scopeName);

        return $envelope['permissions'];
    }

    public function effectivePermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $scopeName = $this->normalizeScopeName($scope);
        $effective = [];

        foreach ($this->scopeLineage($scopeName) as $ancestor) {
            $envelope = $this->readPrincipalScopeEnvelope($principalId, $ancestor);

            foreach ($envelope['roles'] as $role) {
                foreach ($role->permissions as $permission) {
                    $effective[$permission->name] = $permission;
                }
            }

            foreach ($envelope['permissions'] as $permission) {
                $effective[$permission->name] = $permission;
            }
        }

        return array_values($effective);
    }

    public function scopesForPrincipal(string $principalId): array
    {
        $principalId = trim($principalId);

        if ($principalId === '') {
            return [];
        }

        $raw = $this->store->get($this->principalIndexKey($principalId));
        $scopes = is_array($raw) ? $raw : [];
        $scopes = is_array($scopes) ? $scopes : [];
        /** @var list<string> $scopeNames */
        $scopeNames = [];

        foreach ($scopes as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $scopeNames[] = trim($candidate);
            }
        }

        $scopeNames = array_values(array_unique($scopeNames));
        sort($scopeNames);

        return array_map(static fn (string $name): Scope => new Scope($name), $scopeNames);
    }

    public function hasPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        $needle = ($permission instanceof Permission ? $permission : Permission::from($permission))->name;

        foreach ($this->effectivePermissionsForPrincipal($principalId, $scope) as $effective) {
            if ($effective->name === $needle) {
                return true;
            }
        }

        return false;
    }

    public function listGrants(array $filters = []): array
    {
        $principalFilter = isset($filters['principal_id']) && is_string($filters['principal_id'])
            ? trim($filters['principal_id'])
            : null;
        $scopeFilter = isset($filters['scope']) ? $this->nullableScopeName($filters['scope']) : null;
        $typeFilter = strtolower(trim((string) ($filters['type'] ?? 'all')));
        $valueFilter = isset($filters['value']) && is_string($filters['value'])
            ? trim($filters['value'])
            : null;

        $candidates = [];

        if ($principalFilter !== null) {
            foreach ($this->scopesForPrincipal($principalFilter) as $scope) {
                $candidates[] = [$principalFilter, (string) $scope];
            }

            if ($candidates === []) {
                $candidates[] = [$principalFilter, (string) new Scope(Scope::GLOBAL)];
            }
        } else {
            $allKeys = $this->discoverPrincipalScopes();

            foreach ($allKeys as [$principalId, $scopeName]) {
                $candidates[] = [$principalId, $scopeName];
            }
        }

        $rows = [];

        foreach ($candidates as [$principalId, $scopeName]) {
            if ($scopeFilter !== null && $scopeFilter !== $scopeName) {
                continue;
            }

            $envelope = $this->readPrincipalScopeEnvelope($principalId, $scopeName);

            if ($typeFilter === 'all' || $typeFilter === 'role') {
                foreach ($envelope['roles'] as $role) {
                    if ($valueFilter !== null && $valueFilter !== $role->name) {
                        continue;
                    }

                    $rows[] = [
                        'principal_id' => $principalId,
                        'scope' => $scopeName,
                        'type' => 'role',
                        'value' => $role->name,
                    ];
                }
            }

            if ($typeFilter === 'all' || $typeFilter === 'permission') {
                foreach ($envelope['permissions'] as $permission) {
                    if ($valueFilter !== null && $valueFilter !== $permission->name) {
                        continue;
                    }

                    $rows[] = [
                        'principal_id' => $principalId,
                        'scope' => $scopeName,
                        'type' => 'permission',
                        'value' => $permission->name,
                    ];
                }
            }
        }

        return $rows;
    }

    public function grantRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool
    {
        $scopeName = $this->normalizeScopeName($scope);
        $roleObject = $role instanceof Role ? $role : new Role($role);

        return $this->withPrincipalScopeEnvelope(
            $principalId,
            $scopeName,
            static function (array $envelope) use ($roleObject): array {
                foreach ($envelope['roles'] as $existing) {
                    if ($existing->name === $roleObject->name) {
                        return $envelope;
                    }
                }

                $envelope['roles'][] = $roleObject;

                return $envelope;
            },
            'authority.grant_role',
        );
    }

    public function grantPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        $scopeName = $this->normalizeScopeName($scope);
        $permissionObject = $permission instanceof Permission ? $permission : Permission::from($permission);

        return $this->withPrincipalScopeEnvelope(
            $principalId,
            $scopeName,
            static function (array $envelope) use ($permissionObject): array {
                foreach ($envelope['permissions'] as $existing) {
                    if ($existing->name === $permissionObject->name) {
                        return $envelope;
                    }
                }

                $envelope['permissions'][] = $permissionObject;

                return $envelope;
            },
            'authority.grant_permission',
        );
    }

    public function revokeRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool
    {
        $scopeName = $this->normalizeScopeName($scope);
        $roleName = ($role instanceof Role ? $role : new Role($role))->name;

        return $this->withPrincipalScopeEnvelope(
            $principalId,
            $scopeName,
            static function (array $envelope) use ($roleName): array {
                $remaining = array_values(array_filter(
                    $envelope['roles'],
                    static fn (Role $candidate): bool => $candidate->name !== $roleName,
                ));

                if (count($remaining) === count($envelope['roles'])) {
                    return $envelope;
                }

                $envelope['roles'] = $remaining;

                return $envelope;
            },
            'authority.revoke_role',
        );
    }

    public function revokePermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        $scopeName = $this->normalizeScopeName($scope);
        $permissionName = ($permission instanceof Permission ? $permission : Permission::from($permission))->name;

        return $this->withPrincipalScopeEnvelope(
            $principalId,
            $scopeName,
            static function (array $envelope) use ($permissionName): array {
                $remaining = array_values(array_filter(
                    $envelope['permissions'],
                    static fn (Permission $candidate): bool => $candidate->name !== $permissionName,
                ));

                if (count($remaining) === count($envelope['permissions'])) {
                    return $envelope;
                }

                $envelope['permissions'] = $remaining;

                return $envelope;
            },
            'authority.revoke_permission',
        );
    }

    /**
     * @param callable(array{roles:list<Role>,permissions:list<Permission>}):array{roles:list<Role>,permissions:list<Permission>} $mutator
     */
    private function withPrincipalScopeEnvelope(
        string $principalId,
        string $scopeName,
        callable $mutator,
        string $reason,
    ): bool {
        $principalId = trim($principalId);

        if ($principalId === '') {
            return false;
        }

        $key = $this->principalScopeKey($principalId, $scopeName);
        $envelope = $this->readPrincipalScopeEnvelope($principalId, $scopeName);
        $mutated = $mutator($envelope);

        if (! $this->envelopeChanged($envelope, $mutated)) {
            return false;
        }

        $payload = [
            'roles' => array_map(
                static fn (Role $role): array => [
                    'name' => $role->name,
                    'permissions' => array_map(
                        static fn (Permission $permission): string => $permission->name,
                        $role->permissions,
                    ),
                ],
                $mutated['roles'],
            ),
            'permissions' => array_map(
                static fn (Permission $permission): string => $permission->name,
                $mutated['permissions'],
            ),
        ];

        $written = $this->store->forever($key, $payload);

        if (! $written) {
            throw new \RuntimeException(sprintf(
                'Unable to persist remote authority envelope for principal [%s] / scope [%s].',
                $principalId,
                $scopeName,
            ));
        }

        $this->rememberPrincipalScope($principalId, $scopeName);
        $this->consistency?->invalidateAuthority($principalId, $scopeName, $reason);

        return true;
    }

    /**
     * @return array{roles:list<Role>,permissions:list<Permission>}
     */
    private function readPrincipalScopeEnvelope(string $principalId, string $scopeName): array
    {
        $principalId = trim($principalId);

        if ($principalId === '') {
            return ['roles' => [], 'permissions' => []];
        }

        $raw = $this->store->get($this->principalScopeKey($principalId, $scopeName));

        if (! is_array($raw)) {
            return ['roles' => [], 'permissions' => []];
        }

        $rolePayloads = is_array($raw['roles'] ?? null) ? $raw['roles'] : [];
        $permissionNames = is_array($raw['permissions'] ?? null) ? $raw['permissions'] : [];
        $roles = [];

        foreach ($rolePayloads as $rolePayload) {
            if (! is_array($rolePayload)) {
                continue;
            }

            $name = isset($rolePayload['name']) && is_string($rolePayload['name']) ? trim($rolePayload['name']) : '';

            if ($name === '') {
                continue;
            }

            $permissionPayloads = is_array($rolePayload['permissions'] ?? null) ? $rolePayload['permissions'] : [];
            $rolePermissions = [];

            foreach ($permissionPayloads as $permissionName) {
                if (! is_string($permissionName) || trim($permissionName) === '') {
                    continue;
                }

                $rolePermissions[] = Permission::from($permissionName);
            }

            $roles[] = new Role($name, $rolePermissions);
        }

        $permissions = [];

        foreach ($permissionNames as $permissionName) {
            if (! is_string($permissionName) || trim($permissionName) === '') {
                continue;
            }

            $permissions[] = Permission::from($permissionName);
        }

        return [
            'roles' => $roles,
            'permissions' => $permissions,
        ];
    }

    /**
     * @param array{roles:list<Role>,permissions:list<Permission>} $left
     * @param array{roles:list<Role>,permissions:list<Permission>} $right
     */
    private function envelopeChanged(array $left, array $right): bool
    {
        $hash = static function (array $envelope): string {
            $roleNames = array_map(static fn (Role $role): string => $role->name, $envelope['roles']);
            sort($roleNames);
            $permissionNames = array_map(static fn (Permission $permission): string => $permission->name, $envelope['permissions']);
            sort($permissionNames);

            return json_encode([$roleNames, $permissionNames], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        };

        return $hash($left) !== $hash($right);
    }

    private function rememberPrincipalScope(string $principalId, string $scopeName): void
    {
        $key = $this->principalIndexKey($principalId);
        $raw = $this->store->get($key);
        $scopes = is_array($raw) ? $raw : [];
        $normalized = [];

        foreach ($scopes as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $normalized[trim($candidate)] = trim($candidate);
            }
        }

        $normalized[$scopeName] = $scopeName;

        $this->store->forever($key, array_values($normalized));

        $registryKey = $this->registryKey();
        $registry = $this->store->get($registryKey);
        $registry = is_array($registry) ? $registry : [];
        $entry = $principalId . '|' . $scopeName;
        $registry[$entry] = true;
        $this->store->forever($registryKey, array_keys($registry));
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private function discoverPrincipalScopes(): array
    {
        $registry = $this->store->get($this->registryKey());
        $registry = is_array($registry) ? $registry : [];
        $pairs = [];

        foreach ($registry as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            $parts = explode('|', $entry, 2);

            if (! isset($parts[1])) {
                continue;
            }

            [$principalId, $scopeName] = $parts;

            if (trim($principalId) === '' || trim($scopeName) === '') {
                continue;
            }

            $pairs[] = [trim($principalId), trim($scopeName)];
        }

        return $pairs;
    }

    /**
     * @return list<string>
     */
    private function scopeLineage(string $scopeName): array
    {
        $scope = new Scope($scopeName);
        $lineage = [];

        while (true) {
            $lineage[] = (string) $scope;

            if ((string) $scope === Scope::GLOBAL) {
                break;
            }

            $parent = $scope->parent();

            if ($parent === null) {
                $lineage[] = Scope::GLOBAL;

                break;
            }

            $scope = $parent;
        }

        return $lineage;
    }

    private function normalizeScopeName(Scope|string $scope): string
    {
        return (string) ($scope instanceof Scope ? $scope : new Scope($scope));
    }

    private function nullableScopeName(mixed $scope): ?string
    {
        if ($scope instanceof Scope) {
            return (string) $scope;
        }

        if (is_string($scope) && trim($scope) !== '') {
            return (string) new Scope($scope);
        }

        return null;
    }

    private function principalScopeKey(string $principalId, string $scopeName): string
    {
        return $this->prefix . '.' . sha1($principalId) . '.' . sha1($scopeName);
    }

    private function principalIndexKey(string $principalId): string
    {
        return $this->prefix . '.index.' . sha1($principalId);
    }

    private function registryKey(): string
    {
        return $this->prefix . '.registry';
    }
}
