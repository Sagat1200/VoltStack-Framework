<?php

declare(strict_types=1);

namespace Quantum\Auth\Identity;

use InvalidArgumentException;

final readonly class IdentityIdentifier
{
    public function __construct(
        public string $value,
    ) {
        if (trim($this->value) === '') {
            throw new InvalidArgumentException('IdentityIdentifier cannot be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
