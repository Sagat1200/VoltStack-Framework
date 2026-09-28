<?php

declare(strict_types=1);

namespace Quantum\Auth\Passwords;

use Quantum\Auth\Exceptions\PasswordExpiredException;

final class RetentionTieredEnforcer
{
    /**
     * @var array<string, int>
     */
    private array $tierDaysMap;

    /**
     * @param array<string, int>|null $tierDaysMap
     */
    public function __construct(?array $tierDaysMap = null)
    {
        $this->tierDaysMap = $tierDaysMap ?? [
            'low' => 365,
            'medium' => 180,
            'high' => 90,
        ];
    }

    public function retentionDaysForTier(string $tier): int
    {
        $normalized = strtolower(trim($tier));

        if ($normalized === '') {
            $normalized = 'medium';
        }

        return $this->tierDaysMap[$normalized] ?? 180;
    }

    /**
     * @param array<string, mixed> $lifecycleMetadata
     */
    public function requiresImmediateExpiry(array $lifecycleMetadata, string $tier, ?int $nowTs = null): bool
    {
        $now = is_int($nowTs) && $nowTs > 0 ? $nowTs : time();
        $lastRotatedAt = isset($lifecycleMetadata['password_last_rotated_at']) && is_int($lifecycleMetadata['password_last_rotated_at']) && $lifecycleMetadata['password_last_rotated_at'] > 0
            ? (int) $lifecycleMetadata['password_last_rotated_at']
            : (isset($lifecycleMetadata['password_created_at']) && is_int($lifecycleMetadata['password_created_at']) && $lifecycleMetadata['password_created_at'] > 0 ? (int) $lifecycleMetadata['password_created_at'] : 0);

        if ($lastRotatedAt <= 0) {
            return false;
        }

        $days = $this->retentionDaysForTier($tier);
        $cutoff = $lastRotatedAt + ($days * 86400);

        return $now >= $cutoff;
    }

    /**
     * @param array<string, mixed> $lifecycleMetadata
     */
    public function enforce(array $lifecycleMetadata, string $tier, ?int $nowTs = null): void
    {
        if ($this->requiresImmediateExpiry($lifecycleMetadata, $tier, $nowTs)) {
            $now = is_int($nowTs) && $nowTs > 0 ? $nowTs : time();
            $lastRotatedAt = isset($lifecycleMetadata['password_last_rotated_at']) && is_int($lifecycleMetadata['password_last_rotated_at']) && $lifecycleMetadata['password_last_rotated_at'] > 0
                ? (int) $lifecycleMetadata['password_last_rotated_at']
                : 0;
            $days = $this->retentionDaysForTier($tier);

            throw new PasswordExpiredException(
                message: sprintf('Password retention policy for tier %s requires rotation every %d days.', $tier, $days),
                passwordCreatedAt: $lastRotatedAt,
                expiresAt: $lastRotatedAt + ($days * 86400),
                expiresAfterSeconds: $days * 86400,
            );
        }
    }
}
