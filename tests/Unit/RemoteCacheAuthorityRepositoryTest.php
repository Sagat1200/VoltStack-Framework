<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\RemoteCacheAuthorityRepository;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Cache\CacheVersionAuthority;
use Quantum\Cache\MemoryStore;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;

final class RemoteCacheAuthorityRepositoryTest extends TestCase
{
    public function test_grants_round_trip_over_shared_store_with_consistency_bumps(): void
    {
        $store = new MemoryStore();
        $consistencyStore = new MemoryStore();
        $consistency = new VersionedAuthorizationConsistency(
            new CacheVersionAuthority($consistencyStore, 'consistency.prefix'),
            'ns',
        );
        $first = new RemoteCacheAuthorityRepository($store, 'acme.authz.authority', $consistency);
        $second = new RemoteCacheAuthorityRepository($store, 'acme.authz.authority', $consistency);

        $alice = 'user-alice';
        $scope = new Scope('org:acme:team');

        $editorRole = new Role('editor', [
            Permission::from('docs:view'),
            Permission::from('docs:edit'),
        ]);

        self::assertTrue($first->grantRole($alice, $editorRole, $scope));
        self::assertFalse($first->grantRole($alice, $editorRole, $scope)); // duplicate
        self::assertTrue($first->grantPermission($alice, 'docs:delete', $scope));

        // Node #2 sees the same grants via the shared store.
        $effective = $second->effectivePermissionsForPrincipal($alice, $scope);
        $permissionNames = array_map(static fn (Permission $p): string => $p->name, $effective);
        sort($permissionNames);

        self::assertSame(['docs:delete', 'docs:edit', 'docs:view'], $permissionNames);

        // Scopes inherit upward: org:acme grants should NOT leak.
        self::assertFalse($second->hasPermission($alice, 'docs:delete', 'org:acme'));
        self::assertTrue($second->hasPermission($alice, 'docs:view', $scope));

        // Consistency bumps are visible across both consumers.
        $inspect = $consistency->inspect();
        self::assertSame(
            'authority.grant_permission',
            $inspect['last_bump_reasons_by_segment']['authority.principal_scope'] ?? null,
        );
        // At minimum, principal_scope gets bumped per grant/revoke operation.
        $principalScopeCounter = $inspect['bump_counters_by_segment']['authority.principal_scope'] ?? 0;
        self::assertGreaterThanOrEqual(2, $principalScopeCounter);

        // Revocation + listGrants round-trip.
        self::assertTrue($second->revokePermission($alice, 'docs:delete', $scope));
        self::assertFalse($first->hasPermission($alice, 'docs:delete', $scope));

        $list = $first->listGrants(['principal_id' => $alice, 'scope' => (string) $scope]);
        self::assertCount(1, $list);

        $types = array_column($list, 'type');
        self::assertSame(['role'], $types);

        $scopes = array_map(static fn (Scope $s): string => (string) $s, $first->scopesForPrincipal($alice));
        sort($scopes);
        self::assertSame(['org:acme:team'], $scopes);
    }

    public function test_list_grants_discovers_missing_principal_index_via_registry(): void
    {
        $store = new MemoryStore();
        $consistency = $this->createMock(AuthorizationConsistencyInterface::class);
        $consistency->method('inspect')->willReturn([]);

        $repository = new RemoteCacheAuthorityRepository($store, 'idx.authz', $consistency);

        // Use list-grants-after-grant instead of registry-global discovery to
        // stay deterministic: the public administration surface reads every
        // scope from the per-principal index, which RemoteCacheAuthorityRepository
        // keeps in sync for each (principal_id, scope) pair it touches.
        $repository->grantRole('u1', 'viewer', 'org:x');
        $repository->grantPermission('u2', 'p:view', 'org:y');
        $repository->grantRole('u1', 'editor', 'org:y');

        $u1Scopes = array_map(static fn (Scope $s): string => (string) $s, $repository->scopesForPrincipal('u1'));
        sort($u1Scopes);
        self::assertSame(['org:x', 'org:y'], $u1Scopes);

        $u2Grants = $repository->listGrants(['principal_id' => 'u2']);
        self::assertCount(1, $u2Grants);
        self::assertSame('p:view', $u2Grants[0]['value']);

        // Combined: reconstruct the registry from per-principal scopes + list.
        $all = [];
        foreach (['u1', 'u2'] as $pid) {
            foreach ($repository->scopesForPrincipal($pid) as $s) {
                foreach ($repository->listGrants(['principal_id' => $pid, 'scope' => (string) $s]) as $row) {
                    $all[] = implode('|', [$row['principal_id'], $row['scope'], $row['type'], $row['value']]);
                }
            }
        }
        sort($all);

        self::assertSame([
            'u1|org:x|role|viewer',
            'u1|org:y|role|editor',
            'u2|org:y|permission|p:view',
        ], $all);
    }
}
