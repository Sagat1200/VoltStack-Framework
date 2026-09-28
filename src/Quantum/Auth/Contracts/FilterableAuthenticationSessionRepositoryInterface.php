<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface FilterableAuthenticationSessionRepositoryInterface extends AuthenticationSessionRepositoryInterface
{
    /**
     * @param array<string, mixed> $criteria Supported keys:
     *   - identity_id: string|array<int, string>
     *   - status: string|array<int, string> (active, revoked, stale, expired)
     *   - session_type: string|array<int, string>
     *   - device_fingerprint: string
     *   - trusted_device_id: string|array<int, string>
     *   - expires_before: int (unix timestamp)
     *   - expires_after: int (unix timestamp)
     *   - issued_before: int (unix timestamp)
     *   - issued_after: int (unix timestamp)
     *   - authentication_method: string|array<int, string>
     *   - limit: int (default: no limit)
     *   - offset: int (default: 0)
     * @return array<int, \Quantum\Auth\Sessions\AuthenticationSession>
     */
    public function findByCriteria(array $criteria): array;

    /**
     * @param array<string, mixed> $criteria Same shape as findByCriteria (without limit/offset).
     */
    public function countByCriteria(array $criteria): int;
}
