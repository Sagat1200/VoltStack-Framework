<?php

declare(strict_types=1);

namespace Quantum\Auth\Passwords;

use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Config\ConfigRepository;

final class PasswordPolicy implements PasswordPolicyInterface
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function accepts(string $plainPassword): bool
    {
        $length = strlen($plainPassword);

        return $length >= $this->minLength()
            && $length <= $this->maxLength();
    }

    public function verify(string $plainPassword, string $passwordHash): bool
    {
        if (! $this->accepts($plainPassword)) {
            return false;
        }

        return password_verify($plainPassword, $passwordHash);
    }

    public function hash(string $plainPassword): string
    {
        return password_hash(
            $plainPassword,
            PASSWORD_DEFAULT,
            $this->rehashOptions(),
        );
    }

    public function needsRehash(string $passwordHash): bool
    {
        return password_needs_rehash(
            $passwordHash,
            PASSWORD_DEFAULT,
            $this->rehashOptions(),
        );
    }

    public function isExpired(int|\DateTimeImmutable $passwordCreatedAt, int|\DateTimeImmutable|null $expiresAt = null): bool
    {
        $now = time();
        $createdAt = $passwordCreatedAt instanceof \DateTimeImmutable
            ? $passwordCreatedAt->getTimestamp()
            : $passwordCreatedAt;

        if ($expiresAt !== null) {
            $expireTs = $expiresAt instanceof \DateTimeImmutable
                ? $expiresAt->getTimestamp()
                : $expiresAt;

            return $now >= $expireTs;
        }

        $expiresAfter = $this->expiresAfterSeconds();

        if ($expiresAfter === null) {
            return false;
        }

        return ($createdAt + $expiresAfter) <= $now;
    }

    public function needsRotation(int $passwordCreatedAt, ?int $rotationWindowSeconds = null): bool
    {
        $window = $rotationWindowSeconds ?? $this->minRotationIntervalSeconds();

        if ($window === null || $window <= 0) {
            return false;
        }

        return (time() - $passwordCreatedAt) >= $window;
    }

    /**
     * @param list<string> $historyHashes
     */
    public function checkAgainstHistory(string $plainPassword, array $historyHashes): bool
    {
        foreach ($historyHashes as $hash) {
            if (! is_string($hash) || trim($hash) === '') {
                continue;
            }

            if (password_verify($plainPassword, $hash)) {
                return false;
            }
        }

        return true;
    }

    private function minLength(): int
    {
        return max(1, (int) $this->config->get('auth.password.min_length', 8));
    }

    private function maxLength(): int
    {
        return max($this->minLength(), (int) $this->config->get('auth.password.max_length', 4096));
    }

    /**
     * @return array<string, mixed>
     */
    private function rehashOptions(): array
    {
        $options = $this->config->get('auth.password.rehash_options', []);

        return is_array($options) ? $options : [];
    }

    private function expiresAfterSeconds(): ?int
    {
        $value = $this->config->get('auth.password.expires_after_seconds');

        if ($value === null) {
            return null;
        }

        $value = is_numeric($value) ? (int) $value : -1;

        return $value > 0 ? $value : null;
    }

    private function minRotationIntervalSeconds(): ?int
    {
        $value = $this->config->get('auth.password.min_rotation_interval_seconds');

        if ($value === null) {
            return null;
        }

        $value = is_numeric($value) ? (int) $value : -1;

        return $value > 0 ? $value : null;
    }
}
