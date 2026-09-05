<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
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
            attributes: ['session_id' => 'session-55'],
        );

        $repository->save($session);

        self::assertSame($session, $repository->find('session-55'));

        $repository->delete('session-55');

        self::assertNull($repository->find('session-55'));
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
        );

        $other = new AuthenticationSession(
            id: new AuthenticationSessionId('session-drop'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 600,
        );

        $expired = new AuthenticationSession(
            id: new AuthenticationSessionId('session-expired'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time() - 600,
            expiresAt: time() - 1,
        );

        $repository->save($active);
        $repository->save($other);
        $repository->save($expired);

        self::assertSame(1, $repository->purgeExpired());
        self::assertNull($repository->find('session-expired'));

        $repository->deleteForIdentity($identity, 'session-keep');

        self::assertNotNull($repository->find('session-keep'));
        self::assertNull($repository->find('session-drop'));
    }
}
