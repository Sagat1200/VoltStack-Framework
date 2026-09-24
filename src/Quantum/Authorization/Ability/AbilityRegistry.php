<?php

declare(strict_types=1);

namespace Quantum\Authorization\Ability;

final class AbilityRegistry
{
    /**
     * @var array<string, Ability>
     */
    private array $abilities = [];

    public function define(string|Ability $ability): Ability
    {
        $normalized = $ability instanceof Ability ? $ability : new Ability($ability);
        $this->abilities[$normalized->name()] = $normalized;

        return $normalized;
    }

    public function has(string|Ability $ability): bool
    {
        $name = $ability instanceof Ability ? $ability->name() : trim($ability);

        return isset($this->abilities[$name]);
    }

    public function get(string|Ability $ability): ?Ability
    {
        $name = $ability instanceof Ability ? $ability->name() : trim($ability);

        return $this->abilities[$name] ?? null;
    }

    /**
     * @return list<Ability>
     */
    public function all(): array
    {
        return array_values($this->abilities);
    }
}
