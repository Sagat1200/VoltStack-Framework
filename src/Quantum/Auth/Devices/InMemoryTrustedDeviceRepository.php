<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

use Quantum\Auth\Contracts\FilterableTrustedDeviceRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Identity\IdentityReference;

final class InMemoryTrustedDeviceRepository implements TrustedDeviceRepositoryInterface, FilterableTrustedDeviceRepositoryInterface
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

    public function all(?int $now = null): array
    {
        return array_values(array_filter(
            $this->devices,
            static fn (TrustedDevice $device): bool => ! $device->isExpired($now),
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

    public function findByCriteria(array $criteria): array
    {
        $limit = isset($criteria['limit']) && is_int($criteria['limit']) && $criteria['limit'] > 0 ? $criteria['limit'] : null;
        $offset = isset($criteria['offset']) && is_int($criteria['offset']) && $criteria['offset'] > 0 ? $criteria['offset'] : 0;
        $now = isset($criteria['_now']) && is_int($criteria['_now']) ? $criteria['_now'] : null;

        $filtered = [];
        $skipped = 0;
        $taken = 0;

        foreach ($this->devices as $device) {
            if (! $this->deviceMatches($device, $criteria, $now)) continue;
            if ($skipped < $offset) { $skipped++; continue; }
            $filtered[] = $device;
            $taken++;
            if ($limit !== null && $taken >= $limit) break;
        }

        return $filtered;
    }

    public function countByCriteria(array $criteria): int
    {
        $count = 0;
        $now = isset($criteria['_now']) && is_int($criteria['_now']) ? $criteria['_now'] : null;
        foreach ($this->devices as $device) {
            if ($this->deviceMatches($device, $criteria, $now)) $count++;
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function deviceMatches(TrustedDevice $device, array $criteria, ?int $now): bool
    {
        if (! isset($criteria['include_expired']) || (is_bool($criteria['include_expired']) && ! $criteria['include_expired'])) {
            if ($device->isExpired($now)) return false;
        }

        $identityId = $device->reference->identifier->value;
        $identityType = $device->reference->type;
        $attrs = is_array($device->attributes) ? $device->attributes : [];
        $status = is_string($attrs['status'] ?? null) ? $attrs['status'] : null;
        $deviceFingerprint = is_string($attrs['device_fingerprint'] ?? null) ? $attrs['device_fingerprint'] : null;
        $deviceId = is_string($attrs['device_id'] ?? null) ? $attrs['device_id'] : null;
        $scope = is_string($attrs['scope'] ?? null) ? $attrs['scope'] : null;

        if (isset($criteria['identity_id'])) {
            $ids = is_array($criteria['identity_id']) ? $criteria['identity_id'] : [$criteria['identity_id']];
            $mapped = array_values(array_filter($ids, static fn (mixed $v): bool => is_string($v) || is_numeric($v)));
            if (! in_array($identityId, array_map(static fn (mixed $v): string => (string)$v, $mapped), true)) return false;
        }

        if (isset($criteria['identity_type']) && is_string($criteria['identity_type']) && $criteria['identity_type'] !== '') {
            if ($identityType !== $criteria['identity_type']) return false;
        }

        if (isset($criteria['device_id'])) {
            $ids = is_array($criteria['device_id']) ? $criteria['device_id'] : [$criteria['device_id']];
            $idsFiltered = array_values(array_filter($ids, static fn (mixed $v): bool => is_string($v) && trim((string)$v) !== ''));
            if ($deviceId === null || ! in_array($deviceId, $idsFiltered, true)) return false;
        }

        if (isset($criteria['device_fingerprint']) && is_string($criteria['device_fingerprint']) && $criteria['device_fingerprint'] !== '') {
            if ($deviceFingerprint === null || $deviceFingerprint !== $criteria['device_fingerprint']) return false;
        }

        if (isset($criteria['device_reference']) && is_string($criteria['device_reference']) && $criteria['device_reference'] !== '') {
            if ($device->deviceReference !== $criteria['device_reference']) return false;
        }

        if (isset($criteria['status']) && $status !== null) {
            $values = is_array($criteria['status']) ? $criteria['status'] : [$criteria['status']];
            if (! in_array($status, array_values(array_filter($values, static fn (mixed $v): bool => is_string($v))), true)) return false;
        }

        if (isset($criteria['scope']) && is_string($criteria['scope']) && $criteria['scope'] !== '') {
            if ($scope === null || $scope !== $criteria['scope']) return false;
        }

        if (isset($criteria['expires_before']) && is_int($criteria['expires_before'])) {
            if ($device->expiresAt === null || $device->expiresAt > $criteria['expires_before']) return false;
        }

        if (isset($criteria['issued_before']) && is_int($criteria['issued_before'])) {
            if ($device->issuedAt > $criteria['issued_before']) return false;
        }

        if (isset($criteria['issued_after']) && is_int($criteria['issued_after'])) {
            if ($device->issuedAt <= $criteria['issued_after']) return false;
        }

        if (isset($criteria['last_seen_before']) && is_int($criteria['last_seen_before'])) {
            if ($device->lastUsedAt === null || $device->lastUsedAt > $criteria['last_seen_before']) return false;
        }

        if (isset($criteria['last_seen_after']) && is_int($criteria['last_seen_after'])) {
            if ($device->lastUsedAt === null || $device->lastUsedAt <= $criteria['last_seen_after']) return false;
        }

        return true;
    }
}
