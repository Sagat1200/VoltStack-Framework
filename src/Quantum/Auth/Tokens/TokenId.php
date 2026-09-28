<?php

declare(strict_types=1);

namespace Quantum\Auth\Tokens;

use InvalidArgumentException;

final readonly class TokenId
{
    public function __construct(
        public string $value,
    ) {
        $value = trim($this->value);

        if ($value === '') {
            throw new InvalidArgumentException('TokenId cannot be empty.');
        }
    }

    public static function generateAccess(): self
    {
        return new self('atk_' . bin2hex(random_bytes(20)));
    }

    public static function generateRefresh(): self
    {
        return new self('rtk_' . bin2hex(random_bytes(24)));
    }

    public function isAccess(): bool
    {
        return str_starts_with($this->value, 'atk_');
    }

    public function isRefresh(): bool
    {
        return str_starts_with($this->value, 'rtk_');
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
