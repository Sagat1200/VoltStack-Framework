<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Tokens\BearerTokenService;
use Quantum\Auth\Tokens\InMemoryOpaqueTokenRepository;
use Quantum\Auth\Tokens\OpaqueRefreshToken;

final class BloqueH1BearerRotationTest extends TestCase
{
    private static function makeIdentityRef(string $id = 'usr_test_01', string $type = 'local'): IdentityReference
    {
        return new IdentityReference(new IdentityIdentifier($id), $type);
    }

    public function test_issue_token_pair_creates_family_id_and_links_access_and_refresh(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo);
        $pair = $svc->issueTokenPair(self::makeIdentityRef());
        $access = $pair['access_token'];
        $refresh = $pair['refresh_token'];
        self::assertNotNull($access->refreshTokenId);
        self::assertNotNull($refresh->accessTokenId);
        self::assertSame($refresh->id->value, $access->refreshTokenId->value);
        self::assertSame($access->id->value, $refresh->accessTokenId->value);
        self::assertNotEmpty($refresh->familyId);
        self::assertMatchesRegularExpression('/^fam_[0-9a-f]{16}$/', (string)$refresh->familyId);
        self::assertSame($refresh->familyId, $access->attributes['family_id'] ?? null);
    }

    public function test_refresh_token_initially_not_consumed_by_default(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo);
        $pair = $svc->issueTokenPair(self::makeIdentityRef());
        /** @var OpaqueRefreshToken $refresh */
        $refresh = $pair['refresh_token'];
        $stored = $repo->findRefreshToken($refresh->id->value);
        self::assertNotNull($stored);
        self::assertFalse($stored->consumed, 'new refresh must NOT be consumed at creation');
        self::assertNull($stored->consumedAt);
        self::assertFalse($stored->revoked);
        self::assertNull($stored->rotatedTo);
    }

    public function test_consume_refresh_token_marks_consumed_true_and_timestamp(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo);
        $pair = $svc->issueTokenPair(self::makeIdentityRef());
        $rtId = $pair['refresh_token']->id->value;
        $now = 1_700_000_100;

        $result = $repo->consumeRefreshToken($rtId, $now);
        self::assertTrue($result['consumed'], 'consumeRefreshToken must return consumed=true on first call');
        self::assertFalse($result['already_consumed']);
        self::assertNotNull($result['previous']);

        $after = $repo->findRefreshToken($rtId);
        self::assertNotNull($after);
        self::assertTrue($after->consumed);
        self::assertSame($now, $after->consumedAt);
    }

    public function test_double_consume_refresh_token_returns_already_consumed_noop(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo);
        $pair = $svc->issueTokenPair(self::makeIdentityRef());
        $rtId = $pair['refresh_token']->id->value;

        $r1 = $repo->consumeRefreshToken($rtId, 100);
        self::assertTrue($r1['consumed']);
        self::assertFalse($r1['already_consumed']);
        $firstConsumedAt = $repo->findRefreshToken($rtId)->consumedAt;

        $r2 = $repo->consumeRefreshToken($rtId, 999_999);
        self::assertFalse($r2['consumed'], 'second consume must be no-op consumed=false');
        self::assertTrue($r2['already_consumed'], 'second consume must be already_consumed=true');
        self::assertSame($firstConsumedAt, $repo->findRefreshToken($rtId)->consumedAt, 'consumedAt must not change on second consume');
    }

    public function test_rotate_refresh_success_generates_new_pair_preserves_family_and_sets_rotated_to(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo);
        $pair = $svc->issueTokenPair(self::makeIdentityRef());
        $origFamily = $pair['refresh_token']->familyId;
        $origRefreshId = $pair['refresh_token']->id->value;
        $origAccessId = $pair['access_token']->id->value;

        $rotate = $svc->rotateRefresh($origRefreshId, 1_700_000_000);
        self::assertSame('rotated', $rotate['status']);
        self::assertNotNull($rotate['new_access_token']);
        self::assertNotNull($rotate['new_refresh_token']);
        self::assertSame(0, $rotate['revoked_count']);

        $newRefresh = $rotate['new_refresh_token'];
        self::assertSame($origFamily, $newRefresh->familyId, 'familyId MUST be preserved across rotation');

        $origStored = $repo->findRefreshToken($origRefreshId);
        self::assertNotNull($origStored);
        self::assertTrue($origStored->consumed);
        self::assertNotNull($origStored->rotatedTo);
        self::assertSame($newRefresh->id->value, $origStored->rotatedTo->value);

        $newAccess = $rotate['new_access_token'];
        self::assertNotSame($origAccessId, $newAccess->id->value);
        $storedNewRefresh = $repo->findRefreshToken($newRefresh->id->value);
        self::assertNotNull($storedNewRefresh);
        self::assertFalse($storedNewRefresh->consumed);
    }

    public function test_rotate_refresh_on_already_consumed_token_triggers_family_revoke(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo);
        $pair = $svc->issueTokenPair(self::makeIdentityRef());
        $origRefreshId = $pair['refresh_token']->id->value;
        $familyId = (string)$pair['refresh_token']->familyId;

        $rotate1 = $svc->rotateRefresh($origRefreshId, 1_700_000_000);
        self::assertSame('rotated', $rotate1['status']);
        $nextRefreshId = $rotate1['new_refresh_token']->id->value;
        $nextAccessId = $rotate1['new_access_token']->id->value;

        $rotate2 = $svc->rotateRefresh($origRefreshId, 1_700_000_500);
        self::assertSame('family_revoked', $rotate2['status'], 'second rotate over same original MUST be reuse detected → family revoked');
        self::assertStringContainsString('reuse', (string)$rotate2['reason_code']);
        self::assertGreaterThanOrEqual(2, $rotate2['revoked_count'], 'revoked_count must include orig refresh + child refresh + 2 accesses (at least 2)');

        $origAfter = $repo->findRefreshToken($origRefreshId);
        self::assertNotNull($origAfter);
        self::assertTrue($origAfter->consumed, 'already consumed preserved');

        $childAfter = $repo->findRefreshToken($nextRefreshId);
        self::assertNotNull($childAfter, 'child refresh must be stored');
        self::assertTrue($childAfter->revoked, 'child refresh from family MUST be revoked after reuse detected');

        $childAccess = $repo->findAccessToken($nextAccessId);
        self::assertNotNull($childAccess);
        self::assertTrue($childAccess->revoked, 'access token linked to revoked family must be revoked too');

        $origAccessAfter = $repo->findAccessToken($pair['access_token']->id->value);
        self::assertNotNull($origAccessAfter);
        self::assertTrue($origAccessAfter->revoked, 'original access (linked to original family) must also be revoked by reuse detection');
    }

    public function test_list_refresh_tokens_for_identity_shows_consumed_flag_after_rotate(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo);
        $ref = self::makeIdentityRef('usr_list_01', 'local');
        $pair = $svc->issueTokenPair($ref);
        $rtId = $pair['refresh_token']->id->value;

        $before = $repo->listRefreshTokensForIdentity('local', 'usr_list_01');
        self::assertCount(1, $before);
        self::assertFalse($before[0]->consumed, 'pre-rotate: consumed false');

        $svc->rotateRefresh($rtId, 1_700_000_000);

        $after = $repo->listRefreshTokensForIdentity('local', 'usr_list_01');
        self::assertCount(2, $after, 'post-rotate: two refresh tokens exist (orig consumed + new unconsumed)');
        $consumedFlags = array_map(static fn (OpaqueRefreshToken $t) => $t->consumed, $after);
        self::assertCount(1, array_filter($consumedFlags, static fn (bool $v) => $v), 'exactly 1 consumed=true (original)');
        self::assertCount(1, array_filter($consumedFlags, static fn (bool $v) => ! $v), 'exactly 1 consumed=false (new rotated refresh)');
    }

    public function test_rotate_refresh_not_found_and_expired_status_codes_handled(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $svc = new BearerTokenService($repo, 10, 10);
        $rNF = $svc->rotateRefresh('rtk_unknown_id_xx');
        self::assertSame('not_found', $rNF['status']);
        self::assertSame('refresh_token_unknown', $rNF['reason_code']);

        $pair = $svc->issueTokenPair(self::makeIdentityRef('usr_exp', 'local'));
        $rtId = $pair['refresh_token']->id->value;
        $rExp = $svc->rotateRefresh($rtId, time() + 100000);
        self::assertSame('expired', $rExp['status']);
        self::assertSame('refresh_token_expired', $rExp['reason_code']);
        self::assertNull($rExp['new_access_token']);
    }
}
