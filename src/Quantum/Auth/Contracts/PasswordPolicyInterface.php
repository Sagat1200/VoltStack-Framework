<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface PasswordPolicyInterface
{
    public function accepts(string $plainPassword): bool;

    public function verify(string $plainPassword, string $passwordHash): bool;

    public function hash(string $plainPassword): string;

    public function needsRehash(string $passwordHash): bool;

    public function isExpired(int|\DateTimeImmutable $passwordCreatedAt, int|\DateTimeImmutable|null $expiresAt = null): bool;

    public function needsRotation(int $passwordCreatedAt, ?int $rotationWindowSeconds = null): bool;

    /**
     * @param list<string> $historyHashes
     */
    public function checkAgainstHistory(string $plainPassword, array $historyHashes): bool;
}