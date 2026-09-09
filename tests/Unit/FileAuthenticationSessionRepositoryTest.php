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
use Quantum\Auth\Sessions\AuthenticationSessionRecoveryReason;

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

            $tombstones = $this->directory . DIRECTORY_SEPARATOR . 'tombstones';
            if (is_dir($tombstones)) {
                foreach ((array) glob($tombstones . DIRECTORY_SEPARATOR . '*.json') as $file) {
                    @unlink((string) $file);
                }

                @rmdir($tombstones);
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
            attributes: [
                'session_id' => 'session-file-88',
                'session_public_id' => 'sess_pub_file88',
            ],
        );

        $repository->save($session);
        $restored = $repository->find('session-file-88');

        self::assertNotNull($restored);
        self::assertSame('session-file-88', $restored->id->value);
        self::assertSame('88', (string) $restored->identity->identifier());
        self::assertSame('password', $restored->method);
        self::assertCount(1, $repository->listForIdentity($identity));
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
            attributes: ['session_public_id' => 'sess_pub_file_keep'],
        ));

        $repository->save(new AuthenticationSession(
            id: new AuthenticationSessionId('file-drop'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 600,
            attributes: ['session_public_id' => 'sess_pub_file_drop'],
        ));

        $repository->save(new AuthenticationSession(
            id: new AuthenticationSessionId('file-expired'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time() - 600,
            expiresAt: time() - 1,
            attributes: ['session_public_id' => 'sess_pub_file_expired'],
        ));

        self::assertSame(1, $repository->purgeExpired());
        self::assertNull($repository->find('file-expired'));
        self::assertSame(
            AuthenticationSessionRecoveryReason::Expired,
            $repository->findRecoveryReason('file-expired'),
        );

        $repository->deleteForIdentity($identity, 'file-keep');

        self::assertNotNull($repository->find('file-keep'));
        self::assertNull($repository->find('file-drop'));
        self::assertSame(
            AuthenticationSessionRecoveryReason::Revoked,
            $repository->findRecoveryReason('file-drop'),
        );
    }

    public function test_it_can_touch_a_file_backed_session_with_updated_metadata(): void
    {
        $repository = new FileAuthenticationSessionRepository($this->directory);
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('109'),
            type: 'user',
            attributes: ['name' => 'File Session User'],
        );

        $session = new AuthenticationSession(
            id: new AuthenticationSessionId('file-touch'),
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: 100,
            expiresAt: 400,
            attributes: [
                'session_public_id' => 'sess_pub_file_touch',
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
                'session_public_id' => 'sess_pub_file_touch',
                'session_last_activity_at' => 180,
                'session_ip_prefix' => '203.0.113.x',
            ],
        ));

        $touched = $repository->find('file-touch');

        self::assertNotNull($touched);
        self::assertSame(180, $touched->attributes['session_last_activity_at'] ?? null);
        self::assertSame('203.0.113.x', $touched->attributes['session_ip_prefix'] ?? null);
    }

    public function test_it_can_purge_tombstones_after_the_retention_window(): void
    {
        $repository = new FileAuthenticationSessionRepository($this->directory, 60);
        $now = time();

        $repository->delete('file-old', AuthenticationSessionRecoveryReason::Revoked);

        self::assertSame(AuthenticationSessionRecoveryReason::Revoked, $repository->findRecoveryReason('file-old'));
        self::assertSame(0, $repository->purgeRecoveryReasons($now + 59));
        self::assertSame(AuthenticationSessionRecoveryReason::Revoked, $repository->findRecoveryReason('file-old'));

        self::assertSame(1, $repository->purgeRecoveryReasons($now + 61));
        self::assertNull($repository->findRecoveryReason('file-old'));
    }
}
