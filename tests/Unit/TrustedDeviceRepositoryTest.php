<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Devices\InMemoryTrustedDeviceRepository;
use Quantum\Auth\Devices\TrustedDevice;
use Quantum\Auth\Devices\TrustedDevicePublicId;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;

final class TrustedDeviceRepositoryTest extends TestCase
{
    public function test_it_stores_lists_and_deletes_trusted_devices(): void
    {
        $repository = new InMemoryTrustedDeviceRepository();
        $reference = new IdentityReference(new IdentityIdentifier('201'), 'user');
        $now = time();
        $device = new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_alpha'),
            reference: $reference,
            deviceReference: 'devref_alpha',
            issuedAt: $now,
            expiresAt: $now + 500,
            lastUsedAt: $now + 50,
            attributes: ['label' => 'Office Laptop'],
        );

        $repository->save($device);

        self::assertSame($device, $repository->find('tdv_alpha'));
        self::assertCount(1, $repository->listForIdentity($reference));
        self::assertSame($device, $repository->findActiveForIdentityAndDevice($reference, 'devref_alpha'));

        $repository->delete('tdv_alpha');

        self::assertNull($repository->find('tdv_alpha'));
        self::assertCount(0, $repository->listForIdentity($reference));
    }

    public function test_it_can_purge_expired_trusted_devices(): void
    {
        $repository = new InMemoryTrustedDeviceRepository();
        $reference = new IdentityReference(new IdentityIdentifier('202'), 'user');
        $now = time();

        $repository->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_keep'),
            reference: $reference,
            deviceReference: 'devref_keep',
            issuedAt: $now,
            expiresAt: $now + 400,
            lastUsedAt: $now + 150,
        ));
        $repository->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_drop'),
            reference: $reference,
            deviceReference: 'devref_drop',
            issuedAt: $now,
            expiresAt: $now + 199,
            lastUsedAt: $now + 120,
        ));

        self::assertSame(1, $repository->purgeExpired($now + 200));
        self::assertNotNull($repository->find('tdv_keep'));
        self::assertNull($repository->find('tdv_drop'));
        self::assertCount(1, $repository->listForIdentity($reference, $now + 200));
    }
}
