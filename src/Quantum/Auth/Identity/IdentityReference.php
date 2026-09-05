<?php

declare(strict_types=1);

namespace Quantum\Auth\Identity;

final readonly class IdentityReference
{
    public function __construct(
        public IdentityIdentifier $identifier,
        public string $type,
    ) {
    }
}
