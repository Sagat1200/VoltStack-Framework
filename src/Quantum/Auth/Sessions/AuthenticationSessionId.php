<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

use InvalidArgumentException;

final readonly class AuthenticationSessionId
{
    public function __construct(
        public string $value,
    ) {
        if (trim($this->value) === '') {
            throw new InvalidArgumentException('AuthenticationSessionId cannot be empty.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(32)));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
