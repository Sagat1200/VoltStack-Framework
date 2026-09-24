<?php

declare(strict_types=1);

namespace Quantum\Authorization\Gate;

use Closure;
use Quantum\Authorization\Ability\Ability;

final class GateRegistry
{
    /**
     * @var array<string, Closure>
     */
    private array $gates = [];

    public function define(string|Ability $ability, callable $gate): void
    {
        $name = $ability instanceof Ability ? $ability->name() : trim($ability);

        if ($name === '') {
            throw new \InvalidArgumentException('Authorization gate ability cannot be empty.');
        }

        $this->gates[$name] = Closure::fromCallable($gate);
    }

    public function has(string|Ability $ability): bool
    {
        $name = $ability instanceof Ability ? $ability->name() : trim($ability);

        return isset($this->gates[$name]);
    }

    public function get(string|Ability $ability): ?Closure
    {
        $name = $ability instanceof Ability ? $ability->name() : trim($ability);

        return $this->gates[$name] ?? null;
    }
}
