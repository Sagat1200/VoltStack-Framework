<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Contracts\PasswordLifecycleAwareProviderInterface;
use Quantum\Auth\Contracts\RecoveryManagerInterface;
use Quantum\Auth\Exceptions\RecoveryPasswordReuseException;
use Quantum\Auth\Exceptions\RecoveryTokenInvalidException;
use Quantum\Auth\Recovery\RecoveryContinuationRequest;
use Quantum\Auth\Recovery\RecoveryPurpose;
use Quantum\Auth\Recovery\RecoveryRequest;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
use Quantum\Auth\Tokens\OpaqueAccessToken;
use Quantum\Auth\Tokens\OpaqueRefreshToken;
use Quantum\Auth\Tokens\TokenId;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class AccountRecoveryManagerTest extends TestCase
{
    public function test_recovery_manager_resets_password_and_revokes_sessions_and_tokens(): void
    {
        $app = new Application(sys_get_temp_dir());
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7101,
                'identifier' => 'recovery@example.com',
                'password_hash' => password_hash('old-secret-123', PASSWORD_DEFAULT),
                'password_rotation_history' => [
                    password_hash('very-old-secret-123', PASSWORD_DEFAULT),
                ],
                'failed_attempts' => 4,
                'lockout_until' => time() + 600,
                'security_state' => 'locked',
                'type' => 'user',
            ],
        ]);

        $manager = $app->make(RecoveryManagerInterface::class);
        $identityProvider = $app->make(IdentityProviderInterface::class);
        $lifecycle = $app->make(PasswordLifecycleAwareProviderInterface::class);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $tokens = $app->make(OpaqueTokenRepositoryInterface::class);

        $identity = $identityProvider->findByIdentifier('recovery@example.com');
        self::assertNotNull($identity);

        $start = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'recovery@example.com',
            transport: 'unit',
        ));

        self::assertTrue($start->accepted);
        self::assertTrue($start->issued);
        self::assertNotNull($start->token);

        $session = new AuthenticationSession(
            id: new AuthenticationSessionId('sess-recovery-1'),
            identity: $identity,
            reference: new \Quantum\Auth\Identity\IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 3600,
            attributes: ['session_public_id' => 'spub-recovery-1'],
        );
        $sessions->save($session);

        $accessId = new TokenId('access-recovery-1');
        $refreshId = new TokenId('refresh-recovery-1');
        $reference = new \Quantum\Auth\Identity\IdentityReference($identity->identifier(), $identity->type());
        $tokens->saveAccessToken(new OpaqueAccessToken(
            id: $accessId,
            reference: $reference,
            issuedAt: time(),
            expiresAt: time() + 3600,
            refreshTokenId: $refreshId,
        ));
        $tokens->saveRefreshToken(new OpaqueRefreshToken(
            id: $refreshId,
            reference: $reference,
            issuedAt: time(),
            expiresAt: time() + 7200,
            accessTokenId: $accessId,
        ));

        $result = $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start->token,
            newPassword: 'N3w-secret-456!',
            transport: 'unit',
        ));

        self::assertTrue($result->completed);
        self::assertSame('7101', $result->identityId);
        self::assertSame(1, $result->sessionsRevoked);
        self::assertSame(2, $result->tokensRevoked);
        self::assertCount(0, $sessions->listForIdentity($identity));
        self::assertTrue($tokens->findAccessToken('access-recovery-1')?->revoked ?? false);
        self::assertTrue($tokens->findRefreshToken('refresh-recovery-1')?->revoked ?? false);
        self::assertTrue(password_verify(
            'N3w-secret-456!',
            (string) $app->make(ConfigRepository::class)->get('auth.providers.local.identities.0.password_hash'),
        ));

        $metadata = $lifecycle->passwordLifecycleMetadataFor($identity);
        self::assertSame(0, $metadata['failed_attempts']);
        self::assertNull($metadata['lockout_until']);
        self::assertSame('active', $metadata['security_state']);

        $this->expectException(RecoveryTokenInvalidException::class);

        $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start->token,
            newPassword: 'Another-secret-789!',
            transport: 'unit',
        ));
    }

    public function test_recovery_manager_rejects_password_reuse_from_current_or_history(): void
    {
        $app = new Application(sys_get_temp_dir());
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7201,
                'identifier' => 'history@example.com',
                'password_hash' => password_hash('current-secret-123', PASSWORD_DEFAULT),
                'password_rotation_history' => [
                    password_hash('old-secret-123', PASSWORD_DEFAULT),
                ],
                'type' => 'user',
            ],
        ]);

        $manager = $app->make(RecoveryManagerInterface::class);
        $start = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'history@example.com',
            transport: 'unit',
        ));

        self::assertNotNull($start->token);

        $this->expectException(RecoveryPasswordReuseException::class);

        $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start->token,
            newPassword: 'old-secret-123',
            transport: 'unit',
        ));
    }
}
