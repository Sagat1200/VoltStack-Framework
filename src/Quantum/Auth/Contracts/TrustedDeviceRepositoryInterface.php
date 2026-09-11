<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Devices\TrustedDevice;
use Quantum\Auth\Identity\IdentityReference;

interface TrustedDeviceRepositoryInterface
{
    public function save(TrustedDevice $device): void;

    public function find(string $publicId): ?TrustedDevice;

    /**
     * @return list<TrustedDevice>
     */
    public function listForIdentity(IdentityReference $reference, ?int $now = null): array;

    /**
     * @return list<TrustedDevice>
     */
    public function all(?int $now = null): array;

    public function findActiveForIdentityAndDevice(
        IdentityReference $reference,
        string $deviceReference,
        ?int $now = null,
    ): ?TrustedDevice;

    public function delete(string $publicId): void;

    public function touch(TrustedDevice $device): void;

    public function purgeExpired(?int $now = null): int;
}
