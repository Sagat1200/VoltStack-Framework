<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;

interface PasswordLifecycleAwareProviderInterface
{
    /**
     * @return array{password_created_at: int, password_last_rotated_at: ?int, password_expires_at: ?int, password_rotation_history: list<string>, failed_attempts: int, lockout_until: ?int, last_failed_attempt_at: ?int}
     */
    public function passwordLifecycleMetadataFor(IdentityInterface $identity): array;
}
