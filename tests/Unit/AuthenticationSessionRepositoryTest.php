<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
use Quantum\Auth\Sessions\AuthenticationSessionRecoveryReason;
use Quantum\Auth\Sessions\InMemoryAuthenticationSessionRepository;

final class AuthenticationSessionRepositoryTest extends TestCase
{
    public function test_it_stores_and_removes_sessions(): void
    {
        $repository = new InMemoryAuthenticationSessionRepository();
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('55'),
            type: 'user',
            attributes: ['name' => 'Volt Session User'],
        );

        $session = new AuthenticationSession(
            id: new AuthenticationSessionId('session-55'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            attributes: [
                'session_id' => 'session-55',
                'session_public_id' => 'sess_pub_session55',
            ],
        );

        $repository->save($session);

        self::assertSame($session, $repository->find('session-55'));
        self::assertCount(1, $repository->listForIdentity($identity));

        $repository->delete('session-55');

        self::assertNull($repository->find('session-55'));
        self::assertSame(
            AuthenticationSessionRecoveryReason::Revoked,
            $repository->findRecoveryReason('session-55'),
        );
    }

    public function test_it_can_delete_other_sessions_for_the_same_identity_and_purge_expired_sessions(): void
    {
        $repository = new InMemoryAuthenticationSessionRepository();
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('77'),
            type: 'user',
            attributes: ['name' => 'Volt Session User'],
        );

        $active = new AuthenticationSession(
            id: new AuthenticationSessionId('session-keep'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 600,
            attributes: ['session_public_id' => 'sess_pub_keep'],
        );

        $other = new AuthenticationSession(
            id: new AuthenticationSessionId('session-drop'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 600,
            attributes: ['session_public_id' => 'sess_pub_drop'],
        );

        $expired = new AuthenticationSession(
            id: new AuthenticationSessionId('session-expired'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time() - 600,
            expiresAt: time() - 1,
            attributes: ['session_public_id' => 'sess_pub_expired'],
        );

        $repository->save($active);
        $repository->save($other);
        $repository->save($expired);

        self::assertSame(1, $repository->purgeExpired());
        self::assertNull($repository->find('session-expired'));
        self::assertSame(
            AuthenticationSessionRecoveryReason::Expired,
            $repository->findRecoveryReason('session-expired'),
        );

        $repository->deleteForIdentity($identity, 'session-keep');

        self::assertNotNull($repository->find('session-keep'));
        self::assertNull($repository->find('session-drop'));
        self::assertSame(
            AuthenticationSessionRecoveryReason::Revoked,
            $repository->findRecoveryReason('session-drop'),
        );
    }

    public function test_it_can_touch_an_existing_session_with_updated_metadata(): void
    {
        $repository = new InMemoryAuthenticationSessionRepository();
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('88'),
            type: 'user',
            attributes: ['name' => 'Volt Session User'],
        );

        $session = new AuthenticationSession(
            id: new AuthenticationSessionId('session-touch'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: 100,
            expiresAt: 200,
            attributes: [
                'session_public_id' => 'sess_pub_touch',
                'session_last_activity_at' => 100,
            ],
        );

        $repository->save($session);
        $repository->touch(new AuthenticationSession(
            id: $session->id,
            identity: $session->identity,
            reference: $session->reference,
            method: $session->method,
            issuedAt: $session->issuedAt,
            expiresAt: $session->expiresAt,
            attributes: [
                'session_public_id' => 'sess_pub_touch',
                'session_last_activity_at' => 150,
                'session_client_family' => 'Chrome',
            ],
        ));

        $touched = $repository->find('session-touch');

        self::assertNotNull($touched);
        self::assertSame(150, $touched->attributes['session_last_activity_at'] ?? null);
        self::assertSame('Chrome', $touched->attributes['session_client_family'] ?? null);
    }

    public function test_it_can_purge_recovery_reasons_after_the_retention_window(): void
    {
        $repository = new InMemoryAuthenticationSessionRepository(60);
        $now = time();

        $repository->delete('session-old', AuthenticationSessionRecoveryReason::Revoked);
        self::assertSame(AuthenticationSessionRecoveryReason::Revoked, $repository->findRecoveryReason('session-old'));

        self::assertSame(0, $repository->purgeRecoveryReasons($now + 59));
        self::assertSame(AuthenticationSessionRecoveryReason::Revoked, $repository->findRecoveryReason('session-old'));

        self::assertSame(1, $repository->purgeRecoveryReasons($now + 61));
        self::assertNull($repository->findRecoveryReason('session-old'));
    }
}
