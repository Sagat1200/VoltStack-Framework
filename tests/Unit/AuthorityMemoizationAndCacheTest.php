<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\CachedAuthorityRepository;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\RequestScopedAuthorityMemoizationCache;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Authorization\Contracts\AuthorityMemoizationCacheInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Cache\LocalVersionAuthority;

final class AuthorityMemoizationAndCacheTest extends TestCase
{
    public function test_cached_repository_returns_same_permissions_on_repeated_calls_without_invoking_inner_twice(): void
    {
        $inner = new SpyCountingInMemoryAuthorityRepository([]);
        $cache = new RequestScopedAuthorityMemoizationCache();
        $cached = new CachedAuthorityRepository($inner, $cache);

        $principalId = 'u_1';
        $scope = new Scope(Scope::GLOBAL);
        $perms1 = $cached->effectivePermissionsForPrincipal($principalId, $scope);
        $perms2 = $cached->effectivePermissionsForPrincipal($principalId, $scope);
        $perms3 = $cached->effectivePermissionsForPrincipal($principalId, $scope);

        self::assertSame(1, $inner->effectiveCalls);
        self::assertSame($perms1, $perms2);
        self::assertSame($perms1, $perms3);
    }

    public function test_different_principal_or_scope_produce_distinct_cache_entries(): void
    {
        $inner = new SpyCountingInMemoryAuthorityRepository([]);
        $cache = new RequestScopedAuthorityMemoizationCache();
        $cached = new CachedAuthorityRepository($inner, $cache);
        $global = new Scope(Scope::GLOBAL);
        $tenant = new Scope('tenant:acme');

        $cached->effectivePermissionsForPrincipal('u_1', $global);
        $cached->effectivePermissionsForPrincipal('u_1', $tenant);
        $cached->effectivePermissionsForPrincipal('u_2', $global);
        $cached->effectivePermissionsForPrincipal('u_1', $global);

        self::assertSame(3, $inner->effectiveCalls);
    }

    public function test_clear_removes_specific_principal_scope_entry_only(): void
    {
        $inner = new SpyCountingInMemoryAuthorityRepository([]);
        $cache = new RequestScopedAuthorityMemoizationCache();
        $cached = new CachedAuthorityRepository($inner, $cache);
        $scope = new Scope(Scope::GLOBAL);

        $cached->effectivePermissionsForPrincipal('u_1', $scope);
        $cached->effectivePermissionsForPrincipal('u_2', $scope);
        self::assertSame(2, $inner->effectiveCalls);

        $cache->clear('u_1', $scope);

        $cached->effectivePermissionsForPrincipal('u_1', $scope);
        $cached->effectivePermissionsForPrincipal('u_2', $scope);
        self::assertSame(3, $inner->effectiveCalls);
    }

    public function test_clear_all_resets_every_cache_entry(): void
    {
        $inner = new SpyCountingInMemoryAuthorityRepository([]);
        $cache = new RequestScopedAuthorityMemoizationCache();
        $cached = new CachedAuthorityRepository($inner, $cache);
        $scope = new Scope(Scope::GLOBAL);

        $cached->effectivePermissionsForPrincipal('u_1', $scope);
        $cached->effectivePermissionsForPrincipal('u_2', $scope);
        self::assertSame(2, $inner->effectiveCalls);

        $cache->clearAll();

        $cached->effectivePermissionsForPrincipal('u_1', $scope);
        $cached->effectivePermissionsForPrincipal('u_2', $scope);
        self::assertSame(4, $inner->effectiveCalls);
    }

    public function test_has_reports_whether_a_principal_scope_pair_is_present(): void
    {
        $cache = new RequestScopedAuthorityMemoizationCache();
        $global = new Scope(Scope::GLOBAL);
        $tenant = new Scope('tenant:acme');

        self::assertFalse($cache->has('u_1', $global));
        $cache->rememberEffectivePermissions('u_1', $global, static fn (): array => []);
        self::assertTrue($cache->has('u_1', $global));
        self::assertFalse($cache->has('u_1', $tenant));
    }

    public function test_delegates_non_effective_permission_methods_to_inner_without_cache(): void
    {
        $inner = new SpyCountingInMemoryAuthorityRepository([
            [
                'principal_id' => 'u_1',
                'roles' => ['admin'],
                'permissions' => ['posts.create'],
            ],
        ]);
        $cache = new RequestScopedAuthorityMemoizationCache();
        $cached = new CachedAuthorityRepository($inner, $cache);
        $scope = new Scope(Scope::GLOBAL);

        self::assertTrue($cached->hasPermission('u_1', Permission::from('posts.create'), $scope));
        self::assertCount(1, $cached->rolesForPrincipal('u_1', $scope));
        self::assertSame(0, $inner->effectiveCalls);
    }

    public function test_cache_lifetime_is_scoped_per_instance_and_does_not_pollute_other_instances(): void
    {
        $inner = new SpyCountingInMemoryAuthorityRepository([]);
        $cacheA = new RequestScopedAuthorityMemoizationCache();
        $cacheB = new RequestScopedAuthorityMemoizationCache();
        $cachedA = new CachedAuthorityRepository($inner, $cacheA);
        $cachedB = new CachedAuthorityRepository($inner, $cacheB);
        $scope = new Scope(Scope::GLOBAL);

        $cachedA->effectivePermissionsForPrincipal('u_1', $scope);
        $cachedA->effectivePermissionsForPrincipal('u_1', $scope);
        $cachedB->effectivePermissionsForPrincipal('u_1', $scope);

        self::assertSame(2, $inner->effectiveCalls);
    }

    public function test_version_bump_invalidates_memoized_entry_inside_same_cache_instance(): void
    {
        $inner = new SpyCountingInMemoryAuthorityRepository([]);
        $consistency = new VersionedAuthorizationConsistency(new LocalVersionAuthority());
        $cache = new RequestScopedAuthorityMemoizationCache($consistency);
        $cached = new CachedAuthorityRepository($inner, $cache);
        $scope = new Scope('tenant:acme');

        $cached->effectivePermissionsForPrincipal('u_1', $scope);
        $cached->effectivePermissionsForPrincipal('u_1', $scope);
        self::assertSame(1, $inner->effectiveCalls);

        $consistency->invalidateAuthority('u_1', $scope);

        $cached->effectivePermissionsForPrincipal('u_1', $scope);
        self::assertSame(2, $inner->effectiveCalls);
    }
}

final class SpyCountingInMemoryAuthorityRepository implements AuthorityRepositoryInterface
{
    public int $effectiveCalls = 0;

    public function __construct(array $seed)
    {
        $this->inner = new InMemoryAuthorityRepository($seed);
    }

    private InMemoryAuthorityRepository $inner;

    public function effectivePermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        $this->effectiveCalls++;

        return $this->inner->effectivePermissionsForPrincipal($principalId, $scope);
    }

    public function hasPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
    {
        return $this->inner->hasPermission($principalId, $permission, $scope);
    }

    public function hasRole(string $principalId, Role $role, Scope|string $scope = Scope::GLOBAL): bool
    {
        return $this->inner->hasRole($principalId, $role, $scope);
    }

    public function rolesForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        return $this->inner->rolesForPrincipal($principalId, $scope);
    }

    public function directPermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
    {
        return $this->inner->directPermissionsForPrincipal($principalId, $scope);
    }

    public function scopesForPrincipal(string $principalId): array
    {
        return $this->inner->scopesForPrincipal($principalId);
    }
}
