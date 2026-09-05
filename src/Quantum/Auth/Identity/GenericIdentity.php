<?php

declare(strict_types=1);

namespace Quantum\Auth\Identity;

final readonly class GenericIdentity implements IdentityInterface
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private IdentityIdentifier $identifier,
        private string $type = 'generic',
        public array $attributes = [],
    ) {}

    public function identifier(): IdentityIdentifier
    {
        return $this->identifier;
    }

    public function type(): string
    {
        return $this->type;
    }
}