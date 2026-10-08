<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Cache\LocalVersionAuthority;

final class AuthorizationConsistencyVersioningTest extends TestCase
{
    public function test_authority_version_changes_when_principal_scope_is_invalidated(): void
    {
        $consistency = new VersionedAuthorizationConsistency(new LocalVersionAuthority());

        $before = $consistency->authorityVersion('u_1', 'tenant:acme');
        $bumped = $consistency->invalidateAuthority('u_1', 'tenant:acme');
        $after = $consistency->authorityVersion('u_1', 'tenant:acme');

        self::assertArrayHasKey('principal', $bumped);
        self::assertArrayHasKey('scope', $bumped);
        self::assertArrayHasKey('principal_scope', $bumped);
        self::assertNotSame($before, $after);
    }

    public function test_relationship_global_invalidation_changes_versions_for_all_scopes(): void
    {
        $consistency = new VersionedAuthorizationConsistency(new LocalVersionAuthority());

        $beforeA = $consistency->relationshipVersion('u_1', 'tenant:acme');
        $beforeB = $consistency->relationshipVersion('u_2', 'global');

        $bumped = $consistency->invalidateRelationships();

        self::assertSame(['global'], array_keys($bumped));
        self::assertNotSame($beforeA, $consistency->relationshipVersion('u_1', 'tenant:acme'));
        self::assertNotSame($beforeB, $consistency->relationshipVersion('u_2', 'global'));
    }

    public function test_describe_methods_include_segment_versions_and_composite_when_principal_and_scope_are_provided(): void
    {
        $consistency = new VersionedAuthorizationConsistency(new LocalVersionAuthority());

        $consistency->invalidateAuthority('u_1', 'tenant:acme');
        $snapshot = $consistency->describeAuthority('u_1', 'tenant:acme');

        self::assertArrayHasKey('global', $snapshot);
        self::assertArrayHasKey('principal', $snapshot);
        self::assertArrayHasKey('scope', $snapshot);
        self::assertArrayHasKey('principal_scope', $snapshot);
        self::assertArrayHasKey('composite', $snapshot);
        self::assertStringContainsString('|', $snapshot['composite']);
    }
}
