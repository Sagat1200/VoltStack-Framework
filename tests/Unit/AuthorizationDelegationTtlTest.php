<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;

final class AuthorizationDelegationTtlTest extends TestCase
{
    public function test_grant_without_expiry_is_not_expired(): void
    {
        $repo = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');

        $granted = $repo->grantDelegation('trustee_1', 'grantor_1', $permission, Scope::GLOBAL);

        self::assertTrue($granted);

        $list = $repo->listDelegations([]);
        self::assertCount(1, $list);
        self::assertNull($list[0]['expires_at'] ?? null);
    }

    public function test_expired_grant_is_omitted_by_default(): void
    {
        $repo = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');

        // Grant that expired an hour ago.
        $repo->grantDelegation('trustee_1', 'grantor_1', $permission, Scope::GLOBAL, '-1 hour');

        $list = $repo->listDelegations([]);
        self::assertCount(0, $list);
    }

    public function test_include_expired_returns_expired_grants(): void
    {
        $repo = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');

        $repo->grantDelegation('trustee_1', 'grantor_1', $permission, Scope::GLOBAL, '-1 hour');

        $list = $repo->listDelegations(['include_expired' => true]);
        self::assertCount(1, $list);
    }

    public function test_future_expiry_remains_visible(): void
    {
        $repo = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');

        $repo->grantDelegation('trustee_1', 'grantor_1', $permission, Scope::GLOBAL, '+1 hour');

        $list = $repo->listDelegations([]);
        self::assertCount(1, $list);
        self::assertNotNull($list[0]['expires_at'] ?? null);
    }

    public function test_iso_expiry_string_is_normalized(): void
    {
        $repo = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');

        $future = (new \DateTimeImmutable('+2 hours'))->format(\DateTimeInterface::ATOM);
        $repo->grantDelegation('trustee_1', 'grantor_1', $permission, Scope::GLOBAL, $future);

        $list = $repo->listDelegations([]);
        self::assertCount(1, $list);
    }

    public function test_revoke_all_removes_matching_grants_and_returns_count(): void
    {
        $repo = new InMemoryAuthorityRepository();
        $role = new Role('editor');

        $repo->grantDelegation('trustee_1', 'grantor_1', $role, 'tenant:acme');
        $repo->grantDelegation('trustee_1', 'grantor_2', $role, 'tenant:acme');
        $repo->grantDelegation('trustee_2', 'grantor_1', $role, 'tenant:acme');

        $count = $repo->revokeAllDelegations(trusteeId: 'trustee_1');

        self::assertSame(2, $count);
        self::assertCount(1, $repo->listDelegations([]));
    }

    public function test_revoke_all_fail_safe_without_args(): void
    {
        $repo = new InMemoryAuthorityRepository();
        $role = new Role('editor');

        $repo->grantDelegation('trustee_1', 'grantor_1', $role, Scope::GLOBAL);

        $count = $repo->revokeAllDelegations();

        self::assertSame(0, $count);
        self::assertCount(1, $repo->listDelegations([]));
    }
}
