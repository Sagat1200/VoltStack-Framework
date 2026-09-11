<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use RuntimeException;

final class FileTrustedDeviceRepository implements TrustedDeviceRepositoryInterface
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
}
