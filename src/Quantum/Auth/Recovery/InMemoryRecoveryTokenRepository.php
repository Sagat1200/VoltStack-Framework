<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryTokenRepositoryInterface;

final class InMemoryRecoveryTokenRepository implements RecoveryTokenRepositoryInterface
{
    /**
     * @var array<string, PasswordResetTokenRecord>
     */
    private array $tokens = [];

    public function save(PasswordResetTokenRecord $token): void
    {
        $this->tokens[$token->id] = $token;
    }

    public function find(string $tokenId): ?PasswordResetTokenRecord
    {
        $token = $this->tokens[$tokenId] ?? null;

        return $token instanceof PasswordResetTokenRecord ? $token : null;
    }

    public function markConsumed(string $tokenId, ?int $consumedAt = null): bool
    {
        $existing = $this->find($tokenId);

        if ($existing === null || $existing->isConsumed()) {
            return false;
        }

        $this->tokens[$tokenId] = new PasswordResetTokenRecord(
            id: $existing->id,
            secretHash: $existing->secretHash,
            reference: $existing->reference,
            identifier: $existing->identifier,
            issuedAt: $existing->issuedAt,
            expiresAt: $existing->expiresAt,
            consumedAt: ($consumedAt ?? time()) > 0 ? ($consumedAt ?? time()) : time(),
            attributes: $existing->attributes,
        );

        return true;
    }

    public function deleteExpired(?int $now = null): int
    {
        $now ??= time();
        $deleted = 0;

        foreach ($this->tokens as $tokenId => $token) {
            if (! $token->isExpired($now)) {
                continue;
            }

            unset($this->tokens[$tokenId]);
            $deleted++;
        }

        return $deleted;
    }
}
