<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
use Quantum\Auth\Sessions\FileAuthenticationSessionRepository;

final class FileAuthenticationSessionRepositoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-auth-session-tests-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach ((array) glob($this->directory . DIRECTORY_SEPARATOR . '*.json') as $file) {
                @unlink((string) $file);
            }

            @rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_it_persists_and_restores_session_from_file(): void
    {
        $repository = new FileAuthenticationSessionRepository($this->directory);
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('88'),
            type: 'user',
            attributes: ['name' => 'File Session User'],
        );

        $session = new AuthenticationSession(
            id: new AuthenticationSessionId('session-file-88'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 300,
            attributes: ['session_id' => 'session-file-88'],
        );

        $repository->save($session);
        $restored = $repository->find('session-file-88');

        self::assertNotNull($restored);
        self::assertSame('session-file-88', $restored->id->value);
        self::assertSame('88', (string) $restored->identity->identifier());
        self::assertSame('password', $restored->method);
    }

    public function test_it_can_delete_other_sessions_and_purge_expired_files(): void
    {
        $repository = new FileAuthenticationSessionRepository($this->directory);
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('99'),
            type: 'user',
            attributes: ['name' => 'File Session User'],
        );

        $repository->save(new AuthenticationSession(
            id: new AuthenticationSessionId('file-keep'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 600,
        ));

        $repository->save(new AuthenticationSession(
            id: new AuthenticationSessionId('file-drop'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 600,
        ));

        $repository->save(new AuthenticationSession(
            id: new AuthenticationSessionId('file-expired'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time() - 600,
            expiresAt: time() - 1,
        ));

        self::assertSame(1, $repository->purgeExpired());
        self::assertNull($repository->find('file-expired'));

        $repository->deleteForIdentity($identity, 'file-keep');

        self::assertNotNull($repository->find('file-keep'));
        self::assertNull($repository->find('file-drop'));
    }
}
