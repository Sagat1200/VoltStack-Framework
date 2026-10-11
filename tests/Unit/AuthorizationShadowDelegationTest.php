<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Scope;

final class AuthorizationShadowDelegationTest extends TestCase
{
    public function test_revoke_soft_deletes_grant_instead_of_removing(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');
        $repository->grantDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme');

        $active = $repository->listDelegations(['trustee_id' => 'trustee_1']);
        self::assertCount(1, $active);

        $revoked = $repository->revokeDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme');
        self::assertTrue($revoked);

        // Por defecto, listDelegations no incluye revocados.
        $activeAfter = $repository->listDelegations(['trustee_id' => 'trustee_1']);
        self::assertCount(0, $activeAfter);

        // Con include_revoked, el grant sigue presente con revoked_at.
        $includingRevoked = $repository->listDelegations([
            'trustee_id' => 'trustee_1',
            'include_revoked' => true,
        ]);
        self::assertCount(1, $includingRevoked);
        self::assertNotNull($includingRevoked[0]['revoked_at']);
    }

    public function test_revoke_already_revoked_is_no_op(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');
        $repository->grantDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme');

        self::assertTrue($repository->revokeDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme'));
        // Segundo revoke sobre el mismo grant ya revocado: no-op.
        self::assertFalse($repository->revokeDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme'));
    }

    public function test_revoke_all_only_counts_newly_revoked(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');
        $repository->grantDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme');
        $repository->grantDelegation('trustee_2', 'grantor_1', $permission, 'tenant:acme');

        $repository->revokeDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme');

        $count = $repository->revokeAllDelegations(null, 'grantor_1', 'tenant:acme');
        // Solo uno de los dos estaba activo.
        self::assertSame(1, $count);
    }

    public function test_grant_reactivates_revoked_grant(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');

        $repository->grantDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme');
        $repository->revokeDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme');

        self::assertCount(0, $repository->listDelegations(['trustee_id' => 'trustee_1']));

        // Re-grant deberia reactivar el grant revocado.
        $reactivated = $repository->grantDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme');
        self::assertTrue($reactivated);

        $active = $repository->listDelegations(['trustee_id' => 'trustee_1']);
        self::assertCount(1, $active);
        self::assertNull($active[0]['revoked_at']);
    }

    public function test_revoke_delegations_by_policy_soft_deletes_matches(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');
        $other = Permission::from('posts.delete');

        $repository->grantDelegation('trustee_1', 'grantor_1', $permission, 'tenant:acme');
        $repository->grantDelegation('trustee_2', 'grantor_1', $other, 'tenant:acme');

        $count = $repository->revokeDelegationsByPolicy(
            static fn (array $grant): bool => ($grant['value'] ?? null) === 'posts.publish',
            'policy.cleanup',
        );

        self::assertSame(1, $count);

        $active = $repository->listDelegations(['grantor_id' => 'grantor_1']);
        self::assertCount(1, $active);
        self::assertSame('posts.delete', $active[0]['value']);
    }

    public function test_delegation_grant_to_array_includes_revoked_at(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $permission = Permission::from('posts.publish');
        $repository->grantDelegation('trustee_1', 'grantor_1', $permission, Scope::GLOBAL);

        $rows = $repository->listDelegations(['trustee_id' => 'trustee_1']);
        self::assertCount(1, $rows);
        self::assertArrayHasKey('revoked_at', $rows[0]);
        self::assertNull($rows[0]['revoked_at']);
    }
}
