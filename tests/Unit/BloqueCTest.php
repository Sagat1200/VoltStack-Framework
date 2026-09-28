<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Authenticators\BearerAuthenticator;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Identity\LocalIdentityProvider;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Tokens\FileOpaqueTokenRepository;
use Quantum\Auth\Tokens\InMemoryOpaqueTokenRepository;
use Quantum\Auth\Tokens\OpaqueAccessToken;
use Quantum\Auth\Tokens\OpaqueRefreshToken;
use Quantum\Auth\Tokens\TokenId;
use Quantum\Config\ConfigRepository;
use RuntimeException;

final class BloqueCTest extends TestCase
{
    public function test_token_id_generates_prefixes(): void
    {
        $access = TokenId::generateAccess();
        $refresh = TokenId::generateRefresh();

        self::assertStringStartsWith('atk_', $access->value);
        self::assertStringStartsWith('rtk_', $refresh->value);
        self::assertTrue($access->isAccess());
        self::assertFalse($access->isRefresh());
        self::assertTrue($refresh->isRefresh());
        self::assertFalse($refresh->isAccess());
        self::assertSame($access->value, (string) $access);
    }

    public function test_token_id_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TokenId('');
    }

    public function test_opaque_access_token_lifecycle(): void
    {
        $now = time();
        $token = new OpaqueAccessToken(
            id: TokenId::generateAccess(),
            reference: new IdentityReference(new IdentityIdentifier('u1'), 'user'),
            issuedAt: $now - 10,
            expiresAt: $now + 3600,
            clientId: 'cli-sample',
            scopes: ['api.read', 'api.write'],
        );

        self::assertFalse($token->isExpired($now));
        self::assertTrue($token->isActive($now));
        self::assertTrue($token->isExpired($now + 7200));
        self::assertFalse($token->isActive($now + 7200));

        $revoked = new OpaqueAccessToken(
            id: $token->id,
            reference: $token->reference,
            issuedAt: $token->issuedAt,
            expiresAt: $token->expiresAt,
            revoked: true,
        );
        self::assertFalse($revoked->isActive($now));
    }

    public function test_opaque_refresh_token_lifecycle(): void
    {
        $now = time();
        $token = new OpaqueRefreshToken(
            id: TokenId::generateRefresh(),
            reference: new IdentityReference(new IdentityIdentifier('u1'), 'user'),
            issuedAt: $now - 10,
            expiresAt: $now + 86400,
        );

        self::assertFalse($token->isExpired($now));
        self::assertTrue($token->isActive($now));
    }

    public function test_in_memory_token_repo_save_find_revoke(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $now = time();
        $accessId = TokenId::generateAccess();

        $repo->saveAccessToken(new OpaqueAccessToken(
            id: $accessId,
            reference: new IdentityReference(new IdentityIdentifier('alice'), 'user'),
            issuedAt: $now - 10,
            expiresAt: $now + 3600,
        ));

        $found = $repo->findAccessToken($accessId->value);
        self::assertNotNull($found);
        self::assertSame($accessId->value, $found->id->value);
        self::assertSame('alice', $found->reference->identifier->value);

        $revoked = $repo->revokeAccessToken($accessId->value);
        self::assertTrue($revoked);

        $after = $repo->findAccessToken($accessId->value);
        self::assertNotNull($after);
        self::assertTrue($after->revoked);

        $noSuch = $repo->revokeAccessToken('nope');
        self::assertFalse($noSuch);
    }

    public function test_in_memory_revoke_all_for_identity(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $now = time();

        for ($i = 1; $i <= 2; $i++) {
            $repo->saveAccessToken(new OpaqueAccessToken(
                id: TokenId::generateAccess(),
                reference: new IdentityReference(new IdentityIdentifier('bob'), 'user'),
                issuedAt: $now - 10,
                expiresAt: $now + 3600,
            ));
        }
        $repo->saveRefreshToken(new OpaqueRefreshToken(
            id: TokenId::generateRefresh(),
            reference: new IdentityReference(new IdentityIdentifier('bob'), 'user'),
            issuedAt: $now - 10,
            expiresAt: $now + 86400,
        ));
        $repo->saveAccessToken(new OpaqueAccessToken(
            id: TokenId::generateAccess(),
            reference: new IdentityReference(new IdentityIdentifier('other'), 'user'),
            issuedAt: $now - 10,
            expiresAt: $now + 3600,
        ));

        $count = $repo->revokeAllForIdentity('user', 'bob');
        self::assertSame(3, $count);

        $tokens = $repo->listAccessTokensForIdentity('user', 'bob');
        foreach ($tokens as $token) {
            self::assertTrue($token->revoked);
        }
    }

    public function test_file_token_repo_round_trip(): void
    {
        $dir = sys_get_temp_dir() . '/voltstack-bloquec-token-file-' . bin2hex(random_bytes(6));
        $repo = new FileOpaqueTokenRepository($dir);

        $now = time();
        $accessId = TokenId::generateAccess();
        $refreshId = TokenId::generateRefresh();

        $repo->saveAccessToken(new OpaqueAccessToken(
            id: $accessId,
            reference: new IdentityReference(new IdentityIdentifier('u-file'), 'user'),
            issuedAt: $now - 10,
            expiresAt: $now + 3600,
            clientId: 'cli-1',
            scopes: ['read', 'write'],
        ));
        $repo->saveRefreshToken(new OpaqueRefreshToken(
            id: $refreshId,
            reference: new IdentityReference(new IdentityIdentifier('u-file'), 'user'),
            issuedAt: $now - 5,
            expiresAt: $now + 86400,
            accessTokenId: $accessId,
        ));

        $reloadedRepo = new FileOpaqueTokenRepository($dir);
        $access = $reloadedRepo->findAccessToken($accessId->value);
        $refresh = $reloadedRepo->findRefreshToken($refreshId->value);

        self::assertNotNull($access);
        self::assertSame('cli-1', $access->clientId);
        self::assertSame(['read', 'write'], $access->scopes);
        self::assertNotNull($refresh);
        self::assertSame($accessId->value, $refresh->accessTokenId?->value);

        $listed = $reloadedRepo->listAccessTokensForIdentity('user', 'u-file');
        self::assertCount(1, $listed);

        $revoked = $reloadedRepo->revokeAccessToken($accessId->value);
        self::assertTrue($revoked);
        $after = $reloadedRepo->findAccessToken($accessId->value);
        self::assertNotNull($after);
        self::assertTrue($after->revoked);
    }

    public function test_bearer_authenticator_supports_bearer_header(): void
    {
        $idp = new LocalIdentityProvider(new ConfigRepository());
        $tokens = new InMemoryOpaqueTokenRepository();
        $bearer = new BearerAuthenticator($idp, $tokens);

        $withBearer = new AuthenticationOperationContext(
            operation: 'authenticate',
            request: new AuthenticationRequest(
                requestId: 'req-1',
                attributes: [
                    'headers' => ['Authorization' => 'Bearer atk_abc123'],
                ],
            ),
        );
        $without = new AuthenticationOperationContext(
            operation: 'authenticate',
            request: new AuthenticationRequest(requestId: 'req-2', attributes: []),
        );

        self::assertTrue($bearer->supports($withBearer));
        self::assertFalse($bearer->supports($without));
    }

    public function test_bearer_authenticator_rejects_unknown_token(): void
    {
        $idp = new LocalIdentityProvider(new ConfigRepository());
        $tokens = new InMemoryOpaqueTokenRepository();
        $bearer = new BearerAuthenticator($idp, $tokens);

        $decision = $bearer->authenticate(new AuthenticationOperationContext(
            operation: 'authenticate',
            request: new AuthenticationRequest(
                requestId: 'req-unknown',
                attributes: ['access_token' => 'atk_nothing'],
            ),
        ));

        self::assertFalse($decision->isAuthenticated());
        self::assertSame('invalid_credentials', $decision->metadata['reason'] ?? null);
    }

    public function test_bearer_authenticator_authenticates_active_token(): void
    {
        $config = new ConfigRepository();
        $config->set('auth.providers.local.identities', [
            [
                'identifier' => 'bearer-user@example.test',
                'type' => 'user',
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'security_state' => 'eligible',
            ],
        ]);

        $idp = new LocalIdentityProvider($config);
        $tokens = new InMemoryOpaqueTokenRepository();
        $now = time();
        $accessId = TokenId::generateAccess();
        $ref = new IdentityReference(new IdentityIdentifier('bearer-user@example.test'), 'user');

        $tokens->saveAccessToken(new OpaqueAccessToken(
            id: $accessId,
            reference: $ref,
            issuedAt: $now - 10,
            expiresAt: $now + 3600,
            clientId: 'my-client',
            scopes: ['api.read', 'api.write'],
        ));

        $bearer = new BearerAuthenticator($idp, $tokens);
        $decision = $bearer->authenticate(new AuthenticationOperationContext(
            operation: 'authenticate',
            request: new AuthenticationRequest(
                requestId: 'req-valid',
                attributes: [
                    'headers' => ['Authorization' => 'Bearer ' . $accessId->value],
                ],
            ),
        ));

        self::assertTrue($decision->isAuthenticated());
        $ctx = $decision->context();
        self::assertNotNull($ctx);
        self::assertSame('bearer', $ctx->method);
        self::assertSame('bearer-user@example.test', $ctx->reference->identifier->value);
        self::assertSame(['api.read', 'api.write'], $decision->metadata()['access_token_scopes'] ?? null);
    }

    public function test_bearer_authenticator_rejects_expired_and_revoked(): void
    {
        $config = new ConfigRepository();
        $config->set('auth.identities', [
            [
                'identifier' => 'expired@example.test',
                'type' => 'user',
                'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
                'security_state' => 'eligible',
            ],
        ]);

        $idp = new LocalIdentityProvider($config);
        $tokens = new InMemoryOpaqueTokenRepository();
        $now = time();

        $expiredId = TokenId::generateAccess();
        $tokens->saveAccessToken(new OpaqueAccessToken(
            id: $expiredId,
            reference: new IdentityReference(new IdentityIdentifier('expired@example.test'), 'user'),
            issuedAt: $now - 10,
            expiresAt: $now - 1,
        ));

        $bearer = new BearerAuthenticator($idp, $tokens);
        $expiredDecision = $bearer->authenticate(new AuthenticationOperationContext(
            operation: 'authenticate',
            request: new AuthenticationRequest(
                requestId: 'req-exp',
                attributes: ['access_token' => $expiredId->value],
            ),
        ));
        self::assertFalse($expiredDecision->isAuthenticated());
        self::assertTrue($expiredDecision->metadata()['token_expired'] ?? false);

        $activeId = TokenId::generateAccess();
        $tokens->saveAccessToken(new OpaqueAccessToken(
            id: $activeId,
            reference: new IdentityReference(new IdentityIdentifier('expired@example.test'), 'user'),
            issuedAt: $now - 10,
            expiresAt: $now + 3600,
        ));
        $tokens->revokeAccessToken($activeId->value);

        $revokedDecision = $bearer->authenticate(new AuthenticationOperationContext(
            operation: 'authenticate',
            request: new AuthenticationRequest(
                requestId: 'req-rev',
                attributes: ['access_token' => $activeId->value],
            ),
        ));
        self::assertFalse($revokedDecision->isAuthenticated());
        self::assertTrue($revokedDecision->metadata()['token_revoked'] ?? false);
    }

    public function test_in_memory_lists_by_identity(): void
    {
        $repo = new InMemoryOpaqueTokenRepository();
        $now = time();

        for ($i = 0; $i < 3; $i++) {
            $repo->saveAccessToken(new OpaqueAccessToken(
                id: TokenId::generateAccess(),
                reference: new IdentityReference(new IdentityIdentifier('i-' . $i), 'user'),
                issuedAt: $now - 10,
                expiresAt: $now + 3600,
            ));
        }

        self::assertCount(1, $repo->listAccessTokensForIdentity('user', 'i-0'));
        self::assertCount(0, $repo->listRefreshTokensForIdentity('user', 'i-0'));
    }
}
