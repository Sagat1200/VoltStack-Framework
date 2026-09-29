<?php

declare(strict_types=1);

namespace Quantum\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Tokens\BearerTokenService;
use Quantum\Auth\Tokens\FileOpaqueTokenRepository;
use Quantum\Auth\Tokens\InMemoryOpaqueTokenRepository;
use Quantum\Auth\Tokens\OpaqueAccessToken;
use Quantum\Auth\Tokens\OpaqueRefreshToken;

final class Bloque3BearerV2Test extends TestCase
{
    private static function makeIdentityRef(string $id = 'usr-b3', string $type = 'user'): IdentityReference
    {
        return new IdentityReference(new IdentityIdentifier($id), $type);
    }

    public function test_b3_01_issue_token_pair_family_id_bound(): void
    {
        $svc = new BearerTokenService(new InMemoryOpaqueTokenRepository(), 3600, 86400);
        $ref = self::makeIdentityRef();
        $result = $svc->issueTokenPair($ref, 'cli-01', ['read', 'write'], null, 1000, ['origin' => 'test']);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $access = $result['access_token'];
        $refresh = $result['refresh_token'];
        $this->assertInstanceOf(OpaqueAccessToken::class, $access);
        $this->assertInstanceOf(OpaqueRefreshToken::class, $refresh);
        $this->assertTrue($access->id->isAccess());
        $this->assertTrue($refresh->id->isRefresh());
        $this->assertSame('cli-01', $access->clientId);
        $this->assertSame(['read', 'write'], $access->scopes);
        $this->assertNotNull($refresh->familyId);
        $this->assertNotEmpty($refresh->familyId);
        $this->assertStringStartsWith('fam_', $refresh->familyId);
        $this->assertSame($refresh->familyId, $access->attributes['family_id'] ?? null);
        $this->assertSame(1000, $access->issuedAt);
        $this->assertSame(1000 + 86400, $refresh->expiresAt);
        $this->assertFalse($refresh->consumed);
        $this->assertNull($refresh->rotatedTo);
    }

    public function test_b3_02_rotate_refresh_creates_new_pair_and_consumes_parent(): void
    {
        $svc = new BearerTokenService(new InMemoryOpaqueTokenRepository());
        $ref = self::makeIdentityRef();
        $initial = $svc->issueTokenPair($ref, 'cli-02', ['a:b'], 'FAM-02', 100);
        $refreshId = $initial['refresh_token']->id->value;
        $rotated = $svc->rotateRefresh($refreshId, 200);
        $this->assertSame('rotated', $rotated['status']);
        $this->assertNotNull($rotated['new_access_token']);
        $this->assertNotNull($rotated['new_refresh_token']);
        $this->assertSame(0, $rotated['revoked_count']);
        $newAccess = $rotated['new_access_token'];
        $newRefresh = $rotated['new_refresh_token'];
        $this->assertSame('FAM-02', $newRefresh->familyId);
        $this->assertNotSame($refreshId, $newRefresh->id->value);
        $this->assertInstanceOf(OpaqueRefreshToken::class, $newRefresh);
        $this->assertInstanceOf(OpaqueAccessToken::class, $newAccess);
        $intro = $svc->introspectRefreshToken($refreshId, 200);
        $this->assertTrue($intro['consumed']);
        $this->assertSame(200, $intro['consumed_at']);
        $this->assertSame($newRefresh->id->value, $intro['rotated_to']);
    }

    public function test_b3_03_reuse_consumed_refresh_revokes_family(): void
    {
        $svc = new BearerTokenService(new InMemoryOpaqueTokenRepository());
        $ref = self::makeIdentityRef();
        $initial = $svc->issueTokenPair($ref, 'cli-03', ['x'], 'FAM-03', 0);
        $parentRefreshId = $initial['refresh_token']->id->value;
        $firstAccessId = $initial['access_token']->id->value;
        $r1 = $svc->rotateRefresh($parentRefreshId, 50);
        $this->assertSame('rotated', $r1['status']);
        $secondAccessId = $r1['new_access_token']->id->value;
        $r2 = $svc->rotateRefresh($parentRefreshId, 100);
        $this->assertSame('family_revoked', $r2['status']);
        $this->assertGreaterThanOrEqual(2, $r2['revoked_count']);
        $introParent = $svc->introspectRefreshToken($parentRefreshId, 100);
        $this->assertTrue($introParent['revoked']);
        $this->assertFalse($introParent['active']);
        $introFirstAccess = $svc->introspectAccessToken($firstAccessId, 100);
        $this->assertFalse($introFirstAccess['active']);
        $this->assertTrue($introFirstAccess['revoked']);
        $introSecondAccess = $svc->introspectAccessToken($secondAccessId, 100);
        $this->assertFalse($introSecondAccess['active']);
        $this->assertTrue($introSecondAccess['revoked']);
        $this->assertSame('refresh_token_reuse_detected_family_revoked', $r2['reason_code']);
    }

    public function test_b3_04_introspect_access_active_then_expired(): void
    {
        $svc = new BearerTokenService(new InMemoryOpaqueTokenRepository(), 100);
        $ref = self::makeIdentityRef('usr-04');
        $t0 = 100000;
        $issued = $svc->issueTokenPair($ref, null, ['profile'], null, $t0);
        $accessId = $issued['access_token']->id->value;
        $active = $svc->introspectAccessToken($accessId, $t0);
        $this->assertTrue($active['active']);
        $this->assertSame('access_token', $active['token_type']);
        $this->assertSame('usr-04', $active['identifier']);
        $this->assertSame(['profile'], $active['scopes']);
        $this->assertSame($t0 + 100, $active['expires_at']);
        $expired = $svc->introspectAccessToken($accessId, $t0 + 10000);
        $this->assertFalse($expired['active']);
        $this->assertFalse($expired['revoked']);
    }

    public function test_b3_05_introspect_refresh_consumed_rotated_link_ok(): void
    {
        $svc = new BearerTokenService(new InMemoryOpaqueTokenRepository());
        $ref = self::makeIdentityRef('usr-05');
        $t0 = 5000;
        $initial = $svc->issueTokenPair($ref, 'c5', ['s1'], null, $t0);
        $refreshId = $initial['refresh_token']->id->value;
        $before = $svc->introspectRefreshToken($refreshId, $t0);
        $this->assertTrue($before['active']);
        $this->assertFalse($before['consumed']);
        $this->assertNull($before['consumed_at']);
        $this->assertNull($before['rotated_to']);
        $this->assertSame('c5', $before['client_id']);
        $rotated = $svc->rotateRefresh($refreshId, $t0 + 10);
        $this->assertSame('rotated', $rotated['status']);
        $after = $svc->introspectRefreshToken($refreshId, $t0 + 20);
        $this->assertTrue($after['consumed']);
        $this->assertSame($t0 + 10, $after['consumed_at']);
        $this->assertSame($rotated['new_refresh_token']->id->value, $after['rotated_to']);
    }

    public function test_b3_06_file_storage_roundtrip_persists_family_and_consumed(): void
    {
        $tmp = sys_get_temp_dir() . '/b3_bearer_' . substr(bin2hex(random_bytes(6)), 0, 10);
        @mkdir($tmp, 0777, true);
        try {
            $storage = new FileOpaqueTokenRepository($tmp);
            $svc = new BearerTokenService($storage);
            $ref = self::makeIdentityRef('usr-06');
            $t0 = 200000;
            $issued = $svc->issueTokenPair($ref, 'c6', ['filescope'], 'MYFAM6', $t0);
            $refreshId = $issued['refresh_token']->id->value;
            $accessId = $issued['access_token']->id->value;

            $svc2 = new BearerTokenService(new FileOpaqueTokenRepository($tmp));
            $refresh = $svc2->introspectRefreshToken($refreshId, $t0);
            $this->assertTrue($refresh['active']);
            $this->assertSame('MYFAM6', $refresh['family_id']);
            $this->assertFalse($refresh['consumed']);
            $access = $svc2->introspectAccessToken($accessId, $t0);
            $this->assertTrue($access['active']);
            $this->assertSame('MYFAM6', $access['family_id']);

            $rot = $svc2->rotateRefresh($refreshId, $t0 + 5);
            $this->assertSame('rotated', $rot['status']);
            $svc3 = new BearerTokenService(new FileOpaqueTokenRepository($tmp));
            $postRotate = $svc3->introspectRefreshToken($refreshId, $t0 + 6);
            $this->assertTrue($postRotate['consumed']);
            $this->assertSame($t0 + 5, $postRotate['consumed_at']);
            $this->assertSame($rot['new_refresh_token']->id->value, $postRotate['rotated_to']);
            $this->assertSame('MYFAM6', $postRotate['family_id']);
        } finally {
            $this->rmDirRecursive($tmp);
        }
    }

    public function test_b3_07_find_by_family_returns_all_siblings(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo);
        $ref = self::makeIdentityRef('u7');
        $p1 = $svc->issueTokenPair($ref, 'c7', [], 'FAM7A', 1);
        $p2 = $svc->issueTokenPair($ref, 'c7', [], 'FAM7A', 2);
        $pOther = $svc->issueTokenPair($ref, 'c7', [], 'FAM7B', 3);
        $list = $repo->findRefreshTokensByFamilyId('FAM7A');
        $this->assertCount(2, $list);
        $ids = array_map(static fn (OpaqueRefreshToken $r): string => $r->id->value, $list);
        $this->assertContains($p1['refresh_token']->id->value, $ids);
        $this->assertContains($p2['refresh_token']->id->value, $ids);
        $this->assertNotContains($pOther['refresh_token']->id->value, $ids);
    }

    public function test_b3_08_revoke_refresh_and_introspect_shows_revoked(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo);
        $ref = self::makeIdentityRef('u8');
        $t0 = 8000;
        $issued = $svc->issueTokenPair($ref, 'c8', ['sc'], null, $t0);
        $refreshId = $issued['refresh_token']->id->value;
        $accessId = $issued['access_token']->id->value;
        $repo->revokeRefreshToken($refreshId);
        $repo->revokeAccessToken($accessId);
        $introRefresh = $svc->introspectRefreshToken($refreshId, $t0);
        $this->assertTrue($introRefresh['revoked']);
        $this->assertFalse($introRefresh['active']);
        $introAccess = $svc->introspectAccessToken($accessId, $t0);
        $this->assertTrue($introAccess['revoked']);
        $this->assertFalse($introAccess['active']);
    }

    private static function rmDirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $entries = @scandir($dir);
        if (! is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                self::rmDirRecursive($full);
            } elseif (is_file($full)) {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
