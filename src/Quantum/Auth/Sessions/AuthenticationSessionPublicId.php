<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

use InvalidArgumentException;

final readonly class AuthenticationSessionPublicId
{
    public function __construct(
        public string $value,
    ) {
        $value = trim($this->value);

        if ($value === '') {
            throw new InvalidArgumentException('AuthenticationSessionPublicId cannot be empty.');
        }

        if (! str_starts_with($value, 'sess_pub_')) {
            throw new InvalidArgumentException('AuthenticationSessionPublicId must use the sess_pub_ prefix.');
        }
    }

    public static function generate(): self
    {
        return new self('sess_pub_' . bin2hex(random_bytes(12)));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}