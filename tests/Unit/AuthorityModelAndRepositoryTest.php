<?php

declare(strict_types=1);

namespace VoltStack\Framework\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\AttributeDefinition;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;

final class AuthorityModelAndRepositoryTest extends TestCase
{
    public function test_permission_from_ability_and_to_ability(): void
    {
        $permission = Permission::from(new Ability('view:posts'));

        self::assertSame('view:posts', $permission->name);
        self::assertSame('view:posts', (string) $permission);

        $ability = $permission->toAbility();
        self::assertSame('view:posts', $ability->name());
    }

    public function test_permission_simple_name_matches_wildcard_via_ability_convention(): void
    {
        // `administrate` como permission wildcard se interpreta asi:
        // para tests usamos el Permission::matches() que si existe wildcard a mano:
        $permission = new Permission('administrate:*');

        self::assertTrue($permission->matches('administrate:anything'));
    }

    public function test_role_grants_and_deduplicates_permissions(): void
    {
        $role = new Role(
            name: 'editor',
            permissions: ['view:posts', 'create:posts', 'edit:posts', 'view:posts'],
        );

        self::assertSame(3, count($role->permissions));
        self::assertTrue($role->grantsPermission('view:posts'));
        self::assertFalse($role->grantsPermission('delete:posts'));
    }

    public function test_scope_contains_and_ancestry(): void
    {
        $org = new Scope('org:acme');
        $workspace = new Scope('org:acme:workspace:engineering');
        $wildcard = Scope::wildcard();

        self::assertTrue($org->contains($workspace));
        self::assertFalse($workspace->contains($org));
        self::assertTrue($wildcard->contains($workspace));
        self::assertTrue($wildcard->contains($org));

        $parent = $workspace->parent();
        self::assertNotNull($parent);
        self::assertSame('org:acme:workspace', (string) $parent);
    }

    public function test_attribute_definition_accepts_enum_and_patterns(): void
    {
        $color = new AttributeDefinition(
            name: 'color',
            type: AttributeDefinition::TYPE_ENUM,
            constraints: ['values' => ['red', 'green', 'blue']],
        );

        self::assertTrue($color->accepts('red'));
        self::assertFalse($color->accepts('yellow'));

        $email = new AttributeDefinition(
            name: 'email',
            type: AttributeDefinition::TYPE_STRING,
            constraints: ['pattern' => '/^[a-z]+@[a-z]+$/'],
        );

        self::assertTrue($email->accepts('foo@bar'));
        self::assertFalse($email->accepts('no-at-sign'));
    }

    public function test_in_memory_authority_repository_resolves_effective_permissions_with_scope_hierarchy(): void
    {
        $repository = new InMemoryAuthorityRepository([
            [
                'principal_id' => 'user-1',
                'scope' => 'org:acme',
                'roles' => [
                    new Role('editor', ['view:posts', 'create:posts']),
                ],
                'permissions' => ['comment:posts'],
            ],
            [
                'principal_id' => 'user-1',
                'scope' => 'org:acme:workspace:engineering',
                'roles' => [],
                'permissions' => ['delete:posts'],
            ],
        ]);

        $effective = $repository->effectivePermissionsForPrincipal(
            'user-1',
            'org:acme:workspace:engineering',
        );

        $names = array_map(static fn (Permission $p): string => $p->name, $effective);
        sort($names);

        self::assertSame(['comment:posts', 'create:posts', 'delete:posts', 'view:posts'], $names);
        self::assertTrue($repository->hasPermission('user-1', 'delete:posts', 'org:acme:workspace:engineering'));
        self::assertTrue($repository->hasPermission('user-1', 'view:posts', 'org:acme:workspace:engineering'));
        self::assertFalse($repository->hasPermission('user-1', 'delete:posts', 'org:acme'));
    }

    public function test_in_memory_authority_repository_has_permission_matches_via_wildcard_permission(): void
    {
        $repository = new InMemoryAuthorityRepository([
            [
                'principal_id' => 'admin-1',
                'permissions' => ['administrate:*'],
            ],
        ]);

        self::assertTrue($repository->hasPermission('admin-1', 'administrate:anything'));
    }

    public function test_scopes_for_principal_lists_granted_scopes(): void
    {
        $repository = new InMemoryAuthorityRepository([
            ['principal_id' => 'u1', 'scope' => 'org:acme', 'permissions' => ['view:posts']],
            ['principal_id' => 'u1', 'scope' => 'org:other', 'permissions' => ['admin']],
        ]);

        $scopes = $repository->scopesForPrincipal('u1');

        self::assertCount(2, $scopes);
        self::assertSame('org:acme', (string) $scopes[0]);
        self::assertSame('org:other', (string) $scopes[1]);
    }

    public function test_revoke_all_clears_user(): void
    {
        $repository = new InMemoryAuthorityRepository([
            ['principal_id' => 'u1', 'permissions' => ['admin']],
        ]);

        self::assertTrue($repository->hasPermission('u1', 'admin'));

        $repository->revokeAll('u1');

        self::assertFalse($repository->hasPermission('u1', 'admin'));
    }
}
