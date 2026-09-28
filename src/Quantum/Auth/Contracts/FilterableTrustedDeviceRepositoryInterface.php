<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface FilterableTrustedDeviceRepositoryInterface extends TrustedDeviceRepositoryInterface
{
    /**
     * @param array<string, mixed> $criteria Supported keys:
     *   - identity_id: string|array<int, string>
     *   - device_id: string|array<int, string>
     *   - device_fingerprint: string
     *   - status: string|array<int, string> (trusted, untrusted, revoked, tombstone)
     *   - expires_before: int (unix timestamp)
     *   - issued_before: int (unix timestamp)
     *   - issued_after: int (unix timestamp)
     *   - last_seen_before: int (unix timestamp)
     *   - last_seen_after: int (unix timestamp)
     *   - scope: string (managed or personal)
     *   - limit: int (default: no limit)
     *   - offset: int (default: 0)
     * @return array<int, \Quantum\Auth\Devices\TrustedDevice>
     */
    public function findByCriteria(array $criteria): array;

    /**
     * @param array<string, mixed> $criteria Same shape as findByCriteria (without limit/offset).
     */
    public function countByCriteria(array $criteria): int;
}
