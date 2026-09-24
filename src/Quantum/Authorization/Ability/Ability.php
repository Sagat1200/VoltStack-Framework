<?php

declare(strict_types=1);

namespace Quantum\Authorization\Ability;

final readonly class Ability
{
    private string $name;

    public function __construct(string $name)
    {
        $normalized = trim($name);

        if ($normalized === '') {
            throw new \InvalidArgumentException('Authorization ability name cannot be empty.');
        }

        $this->name = $normalized;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function equals(self|string $ability): bool
    {
        return $this->name === ($ability instanceof self ? $ability->name() : trim($ability));
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
