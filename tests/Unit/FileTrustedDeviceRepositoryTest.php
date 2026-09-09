<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Devices\FileTrustedDeviceRepository;
use Quantum\Auth\Devices\TrustedDevice;
use Quantum\Auth\Devices\TrustedDevicePublicId;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;

final class FileTrustedDeviceRepositoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-auth-trusted-device-tests-' . bin2hex(random_bytes(4));
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

    public function test_it_persists_and_restores_trusted_devices_from_file(): void
    {
        $repository = new FileTrustedDeviceRepository($this->directory);
        $reference = new IdentityReference(new IdentityIdentifier('301'), 'user');
        $now = time();
        $device = new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_file_alpha'),
            reference: $reference,
            deviceReference: 'devref_file_alpha',
            issuedAt: $now,
            expiresAt: $now + 400,
            lastUsedAt: $now + 150,
            attributes: ['label' => 'Office Workstation'],
        );

        $repository->save($device);
        $restored = $repository->find('tdv_file_alpha');

        self::assertNotNull($restored);
        self::assertSame('tdv_file_alpha', $restored->publicId->value);
        self::assertSame('devref_file_alpha', $restored->deviceReference);
        self::assertSame('Office Workstation', $restored->label());
        self::assertCount(1, $repository->listForIdentity($reference));
        self::assertNotNull($repository->findActiveForIdentityAndDevice($reference, 'devref_file_alpha'));
    }

    public function test_it_can_purge_expired_trusted_devices_from_file(): void
    {
        $repository = new FileTrustedDeviceRepository($this->directory);
        $reference = new IdentityReference(new IdentityIdentifier('302'), 'user');
        $now = time();

        $repository->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_file_keep'),
            reference: $reference,
            deviceReference: 'devref_file_keep',
            issuedAt: $now,
            expiresAt: $now + 500,
            lastUsedAt: $now + 180,
        ));
        $repository->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_file_drop'),
            reference: $reference,
            deviceReference: 'devref_file_drop',
            issuedAt: $now,
            expiresAt: $now + 200,
            lastUsedAt: $now + 150,
        ));

        self::assertSame(1, $repository->purgeExpired($now + 200));
        self::assertNotNull($repository->find('tdv_file_keep'));
        self::assertNull($repository->find('tdv_file_drop'));
        self::assertCount(1, $repository->listForIdentity($reference, $now + 200));
    }
}
