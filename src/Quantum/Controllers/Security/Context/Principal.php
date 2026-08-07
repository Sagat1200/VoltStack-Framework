<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Context;

use Quantum\Controllers\Security\Contracts\PrincipalInterface;

final readonly class Principal implements PrincipalInterface
{
    public function __construct(
        public string $id,
        public PrincipalType $type,
        public bool $authenticated,
        public array $claims = [],
    ) {}

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

    public static function anonymous(): self
    {
        return new self(
            id: 'anon-' . bin2hex(random_bytes(8)),
            type: PrincipalType::Anonymous,
            authenticated: false,
            claims: [],
        );
    }
}
