<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;

interface DistributedPasswordGovernanceProviderInterface
{
    public function bulkInvalidateByIdentifierPrefix(string $identifierPrefix, string $reason): int;

    public function saveRotationReceipt(
        IdentityInterface $identity,
        string $previousHash,
        string $newHash,
        int $rotatedAt,
        string $rotatedByActorSessionPublicId,
        ?string $reason = null,
    ): bool;

    public function retentionTierFor(IdentityInterface $identity): string;

    /**
     * @return array{score: int, issues: list<string>, passes: bool}
     */
    public function credentialStrengthCheck(string $rawPassword): array;

    /**
     * @return array{by_identifier: int, by_device_ref: int, by_ip_prefix: int, window_seconds: int}
     */
    public function multiDimLockoutThresholds(): array;
}
