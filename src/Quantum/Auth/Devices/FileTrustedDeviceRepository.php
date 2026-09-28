<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

use Quantum\Auth\Contracts\FilterableTrustedDeviceRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use RuntimeException;

final class FileTrustedDeviceRepository implements TrustedDeviceRepositoryInterface, FilterableTrustedDeviceRepositoryInterface
{
    public function __construct(
        private readonly string $directory,
    ) {}

    public function save(TrustedDevice $device): void
    {
        $this->ensureDirectory();

        $payload = json_encode([
            'public_id' => $device->publicId->value,
            'reference' => [
                'identifier' => $device->reference->identifier->value,
                'type' => $device->reference->type,
            ],
            'device_reference' => $device->deviceReference,
            'issued_at' => $device->issuedAt,
            'expires_at' => $device->expiresAt,
            'last_used_at' => $device->lastUsedAt,
            'attributes' => $device->attributes,
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->pathFor($device->publicId->value), $payload, LOCK_EX);
    }

    public function find(string $publicId): ?TrustedDevice
    {
        $path = $this->pathFor($publicId);

        if (! is_file($path)) {
            return null;
        }

        return $this->decode((string) $publicId, $path);
    }

    public function listForIdentity(IdentityReference $reference, ?int $now = null): array
    {
        $devices = [];

        foreach ($this->deviceFiles() as $file) {
            $device = $this->decode(null, $file);

            if ($device === null || $device->isExpired($now)) {
                continue;
            }

            if ($device->reference->identifier->value !== $reference->identifier->value) {
                continue;
            }

            if ($device->reference->type !== $reference->type) {
                continue;
            }

            $devices[] = $device;
        }

        return $devices;
    }

    public function all(?int $now = null): array
    {
        $devices = [];

        foreach ($this->deviceFiles() as $file) {
            $device = $this->decode(null, $file);

            if ($device === null || $device->isExpired($now)) {
                continue;
            }

            $devices[] = $device;
        }

        return $devices;
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
        $path = $this->pathFor($publicId);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function touch(TrustedDevice $device): void
    {
        $this->save($device);
    }

    public function purgeExpired(?int $now = null): int
    {
        $deleted = 0;

        foreach ($this->deviceFiles() as $file) {
            $device = $this->decode(null, $file);

            if ($device === null || ! $device->isExpired($now)) {
                continue;
            }

            if (! @unlink($file) && is_file($file)) {
                throw new RuntimeException(sprintf('Unable to remove trusted device [%s].', $file));
            }

            $deleted++;
        }

        return $deleted;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (! @mkdir($this->directory, 0777, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create trusted device directory [%s].', $this->directory));
        }
    }

    private function pathFor(string $publicId): string
    {
        return rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . $publicId . '.json';
    }

    /**
     * @return list<string>
     */
    private function deviceFiles(): array
    {
        $this->ensureDirectory();

        $files = glob(rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . '*.json');

        if ($files === false) {
            return [];
        }

        return array_values(array_filter($files, static fn (mixed $file): bool => is_string($file)));
    }

    private function decode(?string $fallbackPublicId, string $path): ?TrustedDevice
    {
        $payload = file_get_contents($path);

        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $reference = is_array($data['reference'] ?? null) ? $data['reference'] : [];
        $publicId = (string) ($data['public_id'] ?? $fallbackPublicId ?? '');
        $deviceReference = (string) ($data['device_reference'] ?? '');

        if ($publicId === '' || $deviceReference === '') {
            return null;
        }

        return new TrustedDevice(
            publicId: new TrustedDevicePublicId($publicId),
            reference: new IdentityReference(
                new IdentityIdentifier((string) ($reference['identifier'] ?? '')),
                (string) ($reference['type'] ?? 'user'),
            ),
            deviceReference: $deviceReference,
            issuedAt: (int) ($data['issued_at'] ?? time()),
            expiresAt: isset($data['expires_at']) && is_numeric($data['expires_at']) ? (int) $data['expires_at'] : null,
            lastUsedAt: isset($data['last_used_at']) && is_numeric($data['last_used_at']) ? (int) $data['last_used_at'] : null,
            attributes: is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
        );
    }

    public function findByCriteria(array $criteria): array
    {
        $limit = isset($criteria['limit']) && is_int($criteria['limit']) && $criteria['limit'] > 0 ? $criteria['limit'] : null;
        $offset = isset($criteria['offset']) && is_int($criteria['offset']) && $criteria['offset'] > 0 ? $criteria['offset'] : 0;
        $now = isset($criteria['_now']) && is_int($criteria['_now']) ? $criteria['_now'] : null;

        $all = $this->allWithExpiredOption($criteria);
        $filtered = [];
        $skipped = 0;
        $taken = 0;

        foreach ($all as $device) {
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

        foreach ($this->allWithExpiredOption($criteria) as $device) {
            if ($this->deviceMatches($device, $criteria, $now)) $count++;
        }
        return $count;
    }

    /**
     * @return array<int, TrustedDevice>
     */
    private function allWithExpiredOption(array $criteria): array
    {
        $includeExpired = isset($criteria['include_expired']) && is_bool($criteria['include_expired']) && $criteria['include_expired'];
        $now = isset($criteria['_now']) && is_int($criteria['_now']) ? $criteria['_now'] : null;

        $devices = [];
        foreach ($this->deviceFiles() as $file) {
            $device = $this->decode(null, $file);
            if ($device === null) continue;
            if (! $includeExpired && $device->isExpired($now)) continue;
            $devices[] = $device;
        }

        return $devices;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function deviceMatches(TrustedDevice $device, array $criteria, ?int $now): bool
    {
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
