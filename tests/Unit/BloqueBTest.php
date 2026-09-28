<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\BulkDeletableSessionRepositoryInterface;
use Quantum\Auth\Contracts\FilterableAuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\FilterableTrustedDeviceRepositoryInterface;
use Quantum\Auth\Contracts\InventoryReconcilerInterface;
use Quantum\Auth\Contracts\SessionRepositoryDriverFactoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryDriverFactoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Devices\FileTrustedDeviceRepository;
use Quantum\Auth\Devices\InMemoryTrustedDeviceRepository;
use Quantum\Auth\Devices\InventoryReconciler;
use Quantum\Auth\Devices\TrustedDevice;
use Quantum\Auth\Devices\TrustedDevicePublicId;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\SessionRepositoryDriverFactory;
use Quantum\Auth\Runtime\TrustedDeviceRepositoryDriverFactory;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
use Quantum\Auth\Sessions\FileAuthenticationSessionRepository;
use Quantum\Auth\Sessions\InMemoryAuthenticationSessionRepository;
use RuntimeException;

final class BloqueBTest extends TestCase
{
    public function test_session_factory_registers_custom_driver_and_makes_instance(): void
    {
        $factory = new SessionRepositoryDriverFactory();

        self::assertTrue($factory->hasDriver('memory'));
        self::assertTrue($factory->hasDriver('file'));
        self::assertFalse($factory->hasDriver('custom-driver'));

        $custom = new InMemoryAuthenticationSessionRepository();
        $factory->registerDriver('custom-driver', static fn () => $custom);

        self::assertTrue($factory->hasDriver('custom-driver'));
        self::assertSame($custom, $factory->make('custom-driver'));
        self::assertInstanceOf(AuthenticationSessionRepositoryInterface::class, $factory->make('memory'));
        self::assertInstanceOf(FileAuthenticationSessionRepository::class, $factory->make('file', [
            'storage_path' => sys_get_temp_dir() . '/voltstack-bloque-b-sessions-' . bin2hex(random_bytes(4)),
        ]));
    }

    public function test_session_factory_throws_for_unknown_driver(): void
    {
        $factory = new SessionRepositoryDriverFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown session repository driver');

        $factory->make('nonexistent-driver');
    }

    public function test_trusted_device_factory_registers_custom_driver(): void
    {
        $factory = new TrustedDeviceRepositoryDriverFactory();

        self::assertTrue($factory->hasDriver('memory'));
        self::assertTrue($factory->hasDriver('file'));
        self::assertFalse($factory->hasDriver('custom-tdv-driver'));

        $custom = new InMemoryTrustedDeviceRepository();
        $factory->registerDriver('custom-tdv-driver', static fn () => $custom);

        self::assertSame($custom, $factory->make('custom-tdv-driver'));
        self::assertInstanceOf(TrustedDeviceRepositoryInterface::class, $factory->make('memory'));
        self::assertInstanceOf(FileTrustedDeviceRepository::class, $factory->make('file', [
            'storage_path' => sys_get_temp_dir() . '/voltstack-bloque-b-tdv-' . bin2hex(random_bytes(4)),
        ]));
    }

    public function test_trusted_device_factory_throws_for_unknown_driver(): void
    {
        $factory = new TrustedDeviceRepositoryDriverFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown trusted device repository driver');

        $factory->make('nope');
    }

    public function test_session_repos_implement_filterable_and_bulk_interfaces(): void
    {
        $memory = new InMemoryAuthenticationSessionRepository();
        $file = new FileAuthenticationSessionRepository(sys_get_temp_dir() . '/voltstack-bloque-b-session-file-' . bin2hex(random_bytes(4)));

        self::assertInstanceOf(FilterableAuthenticationSessionRepositoryInterface::class, $memory);
        self::assertInstanceOf(BulkDeletableSessionRepositoryInterface::class, $memory);
        self::assertInstanceOf(FilterableAuthenticationSessionRepositoryInterface::class, $file);
        self::assertInstanceOf(BulkDeletableSessionRepositoryInterface::class, $file);
    }

    public function test_trusted_device_repos_implement_filterable_interface(): void
    {
        $memory = new InMemoryTrustedDeviceRepository();
        $file = new FileTrustedDeviceRepository(sys_get_temp_dir() . '/voltstack-bloque-b-tdv-file-' . bin2hex(random_bytes(4)));

        self::assertInstanceOf(FilterableTrustedDeviceRepositoryInterface::class, $memory);
        self::assertInstanceOf(FilterableTrustedDeviceRepositoryInterface::class, $file);
    }

    public function test_filterable_session_find_and_count_with_identity_criteria(): void
    {
        $repo = new InMemoryAuthenticationSessionRepository();

        $identityA = new GenericIdentity(new IdentityIdentifier('a'), 'user');
        $identityB = new GenericIdentity(new IdentityIdentifier('b'), 'user');
        $now = time();

        for ($i = 1; $i <= 4; $i++) {
            $repo->save(new AuthenticationSession(
                id: new AuthenticationSessionId('sess-a-' . $i),
                identity: $identityA,
                reference: new IdentityReference($identityA->identifier(), $identityA->type()),
                method: 'password',
                issuedAt: $now - 100,
                expiresAt: $now + 3600,
                attributes: [
                    'status' => 'active',
                    'authentication_method' => 'password',
                    'session_type' => 'web',
                    'device_fingerprint' => ($i % 2 === 0) ? 'fp-even' : 'fp-odd',
                ],
            ));
        }

        $repo->save(new AuthenticationSession(
            id: new AuthenticationSessionId('sess-b-1'),
            identity: $identityB,
            reference: new IdentityReference($identityB->identifier(), $identityB->type()),
            method: 'password',
            issuedAt: $now - 100,
            expiresAt: $now + 3600,
            attributes: ['status' => 'active', 'authentication_method' => 'password'],
        ));

        $found = $repo->findByCriteria(['identity_id' => 'a']);
        self::assertCount(4, $found);

        $countIdentityA = $repo->countByCriteria(['identity_id' => 'a']);
        self::assertSame(4, $countIdentityA);

        $identityBcount = $repo->countByCriteria(['identity_id' => 'b']);
        self::assertSame(1, $identityBcount);

        $limited = $repo->findByCriteria(['identity_id' => 'a', 'limit' => 2]);
        self::assertCount(2, $limited);

        $fpEven = $repo->findByCriteria(['device_fingerprint' => 'fp-even']);
        self::assertCount(2, $fpEven);
    }

    public function test_bulk_session_delete_by_criteria_and_ids(): void
    {
        $repo = new InMemoryAuthenticationSessionRepository();

        $identityA = new GenericIdentity(new IdentityIdentifier('x'), 'user');
        $identityB = new GenericIdentity(new IdentityIdentifier('y'), 'user');
        $now = time();

        for ($i = 1; $i <= 3; $i++) {
            $repo->save(new AuthenticationSession(
                id: new AuthenticationSessionId('x-' . $i),
                identity: $identityA,
                reference: new IdentityReference($identityA->identifier(), $identityA->type()),
                method: 'password',
                issuedAt: $now - 1000,
                expiresAt: $now - 100,
                attributes: ['status' => 'expired'],
            ));
        }

        $repo->save(new AuthenticationSession(
            id: new AuthenticationSessionId('y-active'),
            identity: $identityB,
            reference: new IdentityReference($identityB->identifier(), $identityB->type()),
            method: 'password',
            issuedAt: $now - 100,
            expiresAt: $now + 3600,
            attributes: ['status' => 'active'],
        ));

        self::assertSame(4, $repo->countByCriteria([]));
        $deleted = $repo->deleteByCriteria(['identity_id' => 'x']);
        self::assertSame(3, $deleted);
        self::assertNull($repo->find('x-1'));
        self::assertNull($repo->find('x-2'));
        self::assertNull($repo->find('x-3'));
        self::assertNotNull($repo->find('y-active'));

        $repo->save(new AuthenticationSession(
            id: new AuthenticationSessionId('y-2'),
            identity: $identityB,
            reference: new IdentityReference($identityB->identifier(), $identityB->type()),
            method: 'password',
            issuedAt: $now - 50,
            expiresAt: $now + 3600,
            attributes: ['status' => 'active'],
        ));

        $deletedByIds = $repo->deleteByIds(['y-active', 'y-2', 'unknown-nothing']);
        self::assertSame(2, $deletedByIds);
    }

    public function test_filterable_trusted_device_find_and_count(): void
    {
        $repo = new InMemoryTrustedDeviceRepository();

        $identity = new GenericIdentity(new IdentityIdentifier('ident-t-1'), 'user');
        $reference = new IdentityReference($identity->identifier(), $identity->type());
        $now = time();

        for ($i = 1; $i <= 3; $i++) {
            $repo->save(new TrustedDevice(
                publicId: new TrustedDevicePublicId('tdv_bloqueb_' . $i),
                reference: $reference,
                deviceReference: 'device-ref-' . $i,
                issuedAt: $now - 1000,
                expiresAt: $now + 86400,
                lastUsedAt: $now - 100,
                attributes: [
                    'device_fingerprint' => 'fp-' . $i,
                    'status' => ($i === 1 ? 'revoked' : 'trusted'),
                    'scope' => ($i === 3 ? 'admin' : 'api'),
                ],
            ));
        }

        $allByIdentity = $repo->findByCriteria(['identity_id' => 'ident-t-1', 'status' => 'trusted']);
        self::assertCount(2, $allByIdentity);

        $countAll = $repo->countByCriteria(['identity_id' => 'ident-t-1', 'status' => 'trusted']);
        self::assertSame(2, $countAll);

        $includeExpired = $repo->findByCriteria(['identity_id' => 'ident-t-1', 'include_expired' => true, 'status' => ['trusted', 'revoked']]);
        self::assertCount(3, $includeExpired);

        $byDeviceRef = $repo->findByCriteria(['device_reference' => 'device-ref-2', 'include_expired' => true]);
        self::assertCount(1, $byDeviceRef);

        $byScope = $repo->findByCriteria(['scope' => 'admin', 'include_expired' => true]);
        self::assertCount(1, $byScope);

        $limited = $repo->findByCriteria(['include_expired' => true, 'limit' => 1]);
        self::assertCount(1, $limited);
    }

    public function test_inventory_reconciler_smoke_empty(): void
    {
        $sessions = new InMemoryAuthenticationSessionRepository();
        $trustedDevices = new InMemoryTrustedDeviceRepository();
        $reconciler = new InventoryReconciler($sessions, $trustedDevices);

        $result = $reconciler->reconcile(null, true);

        self::assertSame(0, $result['scanned']);
        self::assertSame(0, $result['updated']);
        self::assertSame(0, $result['promoted']);
        self::assertSame(0, $result['demoted']);
        self::assertSame(0, $result['skipped_without_device']);
        self::assertGreaterThan(0, $result['evaluated_at']);
        self::assertInstanceOf(InventoryReconcilerInterface::class, $reconciler);
    }
}
