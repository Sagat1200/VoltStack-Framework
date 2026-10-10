<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Cache\FileVersionAuthority;
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

    public function test_invalidate_records_reason_bump_counters_and_timestamps_in_process(): void
    {
        $consistency = new VersionedAuthorizationConsistency(new LocalVersionAuthority());

        self::assertNull($consistency->lastBumpAt());
        self::assertSame([], $consistency->bumpCounters());
        self::assertSame([], $consistency->bumpReasons());

        $consistency->invalidateRelationships(null, null, 'global rotation');
        $consistency->invalidateAuthority('u_1', 'tenant:acme', 'admin grant sync');

        $counters = $consistency->bumpCounters();
        $reasons = $consistency->bumpReasons();

        self::assertNotEmpty($consistency->lastBumpAt());
        self::assertSame(1, $counters['relationships.global'] ?? null);
        self::assertSame(1, $counters['authority.principal'] ?? null);
        self::assertSame(1, $counters['authority.scope'] ?? null);
        self::assertSame(1, $counters['authority.principal_scope'] ?? null);
        self::assertSame('global rotation', $reasons['relationships.global'] ?? null);
        self::assertSame('admin grant sync', $reasons['authority.principal'] ?? null);
        self::assertSame('admin grant sync', $reasons['authority.scope'] ?? null);
        self::assertSame('admin grant sync', $reasons['authority.principal_scope'] ?? null);
    }

    public function test_inspect_exposes_namespace_last_bump_and_version_authority_info(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_consistency_inspect_', true);
        @mkdir($tempDir, 0o777, true);

        $versions = new FileVersionAuthority($tempDir);
        $consistency = new VersionedAuthorizationConsistency($versions, 'custom.authz.consistency');

        $consistency->invalidateRelationships(null, null);

        $inspect = $consistency->inspect();

        self::assertSame(VersionedAuthorizationConsistency::class, $inspect['implementation'] ?? null);
        self::assertSame('custom.authz.consistency', $inspect['namespace'] ?? null);
        self::assertSame(FileVersionAuthority::class, $inspect['version_authority'] ?? null);
        self::assertSame('file', $inspect['version_authority_info']['kind'] ?? null);
        self::assertSame(rtrim(str_replace('\\', '/', $tempDir), '/'), rtrim(str_replace('\\', '/', $inspect['version_authority_info']['storage_path'] ?? ''), '/'));
        self::assertNotEmpty($inspect['last_bump_at']);
        self::assertSame(1, $inspect['bump_counters_by_segment']['relationships.global'] ?? null);
        self::assertArrayHasKey('relationships.global', $inspect['last_bump_reasons_by_segment'] ?? []);
        self::assertSame('', $inspect['last_bump_reasons_by_segment']['relationships.global'] ?? null);
    }

    public function test_describe_version_authority_reports_cache_kind_and_store_prefix(): void
    {
        $store = new \Quantum\Cache\MemoryStore();
        $versions = new \Quantum\Cache\CacheVersionAuthority($store, 'my.authz.versions');
        $consistency = new VersionedAuthorizationConsistency($versions);

        $info = $consistency->describeVersionAuthority();

        self::assertSame('cache', $info['kind'] ?? null);
        self::assertSame($store::class, $info['store'] ?? null);
        self::assertSame('my.authz.versions', $info['prefix'] ?? null);
    }
}
