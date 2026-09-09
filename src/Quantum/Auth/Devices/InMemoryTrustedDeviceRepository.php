<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Identity\IdentityReference;

final class InMemoryTrustedDeviceRepository implements TrustedDeviceRepositoryInterface
{
    /**
     * @var array<string, TrustedDevice>
     */
    private array $devices = [];

    public function save(TrustedDevice $device): void
    {
        $this->devices[$device->publicId->value] = $device;
    }

    public function find(string $publicId): ?TrustedDevice
    {
        return $this->devices[$publicId] ?? null;
    }

    public function listForIdentity(IdentityReference $reference, ?int $now = null): array
    {
        return array_values(array_filter(
            $this->devices,
            static fn (TrustedDevice $device): bool => ! $device->isExpired($now)
                && $device->reference->identifier->value === $reference->identifier->value
                && $device->reference->type === $reference->type,
        ));
    }

    public function findActiveForIdentityAndDevice(
        IdentityReference $reference,
        string $deviceReference,
        ?int $now = null,
    ): ?TrustedDevice {
        $deviceReference = trim($deviceReference);

        if ($deviceReference === '') {
            return null;
        }

        foreach ($this->listForIdentity($reference, $now) as $device) {
            if ($device->deviceReference === $deviceReference) {
                return $device;
            }
        }

        return null;
    }

    public function delete(string $publicId): void
    {
        unset($this->devices[$publicId]);
    }

    public function touch(TrustedDevice $device): void
    {
        $this->save($device);
    }

    public function purgeExpired(?int $now = null): int
    {
        $deleted = 0;

        foreach ($this->devices as $publicId => $device) {
            if (! $device->isExpired($now)) {
                continue;
            }

            unset($this->devices[$publicId]);
            $deleted++;
        }

        return $deleted;
    }
}
