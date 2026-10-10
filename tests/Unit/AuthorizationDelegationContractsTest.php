<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\DelegationGrant;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Contracts\DelegationAdministrationInterface;

final class AuthorizationDelegationContractsTest extends TestCase
{
    public function test_delegation_grant_value_object_is_serializable_and_json_safe(): void
    {
        $grantedAt = '2025-01-15T10:30:00+00:00';
        $grant = new DelegationGrant(
            trusteeId: 'trustee_1',
            grantorId: 'grantor_2',
            scope: Scope::GLOBAL,
            type: 'permission',
            value: 'posts.publish',
            grantedAt: $grantedAt,
        );

        self::assertSame('trustee_1', $grant->trusteeId);
        self::assertSame('grantor_2', $grant->grantorId);
        self::assertSame(Scope::GLOBAL, (string) $grant->scope);
        self::assertSame('permission', $grant->type);
        self::assertSame('posts.publish', $grant->value);
        self::assertSame($grantedAt, $grant->grantedAt);

        $array = $grant->toArray();
        self::assertSame([
            'trustee_id' => 'trustee_1',
            'grantor_id' => 'grantor_2',
            'scope' => Scope::GLOBAL,
            'type' => 'permission',
            'value' => 'posts.publish',
            'granted_at' => $grantedAt,
        ], $array);

        $json = json_encode($grant, JSON_THROW_ON_ERROR);
        self::assertJson($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('trustee_1', $decoded['trustee_id']);
        self::assertSame('posts.publish', $decoded['value']);
    }

    public function test_in_memory_authority_repository_implements_delegation_administration(): void
    {
        $repository = new InMemoryAuthorityRepository();
        self::assertInstanceOf(DelegationAdministrationInterface::class, $repository);
    }

    public function test_grant_and_revoke_delegation_permission_shape_round_trip(): void
    {
        $repository = new InMemoryAuthorityRepository();

        $permission = Permission::from('posts.publish');
        $granted = $repository->grantDelegation('trustee_1', 'grantor_2', $permission, 'tenant:acme');

        self::assertTrue($granted);

        $rows = $repository->listDelegations([
            'trustee_id' => 'trustee_1',
            'grantor_id' => 'grantor_2',
            'scope' => 'tenant:acme',
        ]);

        self::assertCount(1, $rows);
        self::assertSame('trustee_1', $rows[0]['trustee_id']);
        self::assertSame('grantor_2', $rows[0]['grantor_id']);
        self::assertSame('tenant:acme', $rows[0]['scope']);
        self::assertSame('permission', $rows[0]['type']);
        self::assertSame('posts.publish', $rows[0]['value']);
        self::assertNotNull($rows[0]['granted_at']);

        $revoked = $repository->revokeDelegation('trustee_1', 'grantor_2', $permission, 'tenant:acme');
        self::assertTrue($revoked);

        self::assertCount(0, $repository->listDelegations([
            'trustee_id' => 'trustee_1',
            'grantor_id' => 'grantor_2',
            'scope' => 'tenant:acme',
        ]));
    }

    public function test_grant_and_revoke_delegation_role_shape_round_trip(): void
    {
        $repository = new InMemoryAuthorityRepository();

        $role = new Role('editor', [Permission::from('posts.publish')]);
        $granted = $repository->grantDelegation('trustee_r', 'grantor_s', $role, Scope::GLOBAL);

        self::assertTrue($granted);

        $rows = $repository->listDelegations([
            'trustee_id' => 'trustee_r',
            'grantor_id' => 'grantor_s',
            'type' => 'role',
        ]);

        self::assertCount(1, $rows);
        self::assertSame('role', $rows[0]['type']);
        self::assertSame('editor', $rows[0]['value']);

        // Filter by value
        $filteredByValue = $repository->listDelegations(['value' => 'editor']);
        self::assertCount(1, $filteredByValue);

        $revoked = $repository->revokeDelegation('trustee_r', 'grantor_s', $role, Scope::GLOBAL);
        self::assertTrue($revoked);

        self::assertCount(0, $repository->listDelegations(['trustee_id' => 'trustee_r']));
    }

    public function test_grant_is_idempotent_and_revoke_returns_false_when_absent(): void
    {
        $repository = new InMemoryAuthorityRepository();

        $perm = Permission::from('items.view');

        $a = $repository->grantDelegation('t_1', 'g_1', $perm, Scope::GLOBAL);
        $b = $repository->grantDelegation('t_1', 'g_1', $perm, Scope::GLOBAL);
        self::assertTrue($a);
        self::assertFalse($b);
        self::assertCount(1, $repository->listDelegations(['trustee_id' => 't_1']));

        self::assertTrue($repository->revokeDelegation('t_1', 'g_1', $perm, Scope::GLOBAL));
        self::assertFalse($repository->revokeDelegation('t_1', 'g_1', $perm, Scope::GLOBAL));
    }
}
