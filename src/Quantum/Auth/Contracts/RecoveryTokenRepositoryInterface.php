<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Recovery\PasswordResetTokenRecord;

interface RecoveryTokenRepositoryInterface
{
    public function save(PasswordResetTokenRecord $token): void;

    public function find(string $tokenId): ?PasswordResetTokenRecord;

    public function markConsumed(string $tokenId, ?int $consumedAt = null): bool;

    public function deleteExpired(?int $now = null): int;
}
