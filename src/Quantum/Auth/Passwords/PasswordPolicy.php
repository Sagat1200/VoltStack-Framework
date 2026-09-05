<?php

declare(strict_types=1);

namespace Quantum\Auth\Passwords;

use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Config\ConfigRepository;

final class PasswordPolicy implements PasswordPolicyInterface
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

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

    public function needsRehash(string $passwordHash): bool
    {
        return password_needs_rehash(
            $passwordHash,
            PASSWORD_DEFAULT,
            $this->rehashOptions(),
        );
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
}
