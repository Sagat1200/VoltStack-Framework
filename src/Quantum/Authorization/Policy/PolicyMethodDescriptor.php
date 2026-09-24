<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy;

final readonly class PolicyMethodDescriptor
{
    /**
     * @param list<string> $abilities
     */
    public function __construct(
        public string $method,
        public array $abilities,
    ) {}

    public function handles(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }
}
