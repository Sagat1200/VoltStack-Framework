<?php

declare(strict_types=1);

namespace Quantum\Authorization\Principal;

use Quantum\Authorization\Contracts\PrincipalInterface;

class Principal implements PrincipalInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        private readonly string $id,
        private readonly PrincipalType $type = PrincipalType::User,
        private readonly bool $authenticated = true,
        private readonly array $claims = [],
    ) {
        if (trim($this->id) === '') {
            throw new \InvalidArgumentException('Authorization principal id cannot be empty.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): PrincipalType
    {
        return $this->type;
    }

    public function authenticated(): bool
    {
        return $this->authenticated;
    }

    public function claims(): array
    {
        return $this->claims;
    }
}
