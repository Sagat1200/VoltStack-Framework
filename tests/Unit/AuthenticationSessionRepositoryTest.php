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
}
