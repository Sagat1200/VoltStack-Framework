<?php

declare(strict_types=1);

namespace Quantum\Container;

use InvalidArgumentException;

final readonly class Binding
{
    public function __construct(
        public string $abstract,
        public mixed $concrete,
        public bool $shared = false,
        public bool $scoped = false,
    ) {
        if ($shared && $scoped) {
            throw new InvalidArgumentException('A binding cannot be shared and scoped at the same time.');
        }
    }

    public static function transient(string $abstract, mixed $concrete): self
    {
        return new self($abstract, $concrete);
    }

    public static function singleton(string $abstract, mixed $concrete): self
    {
        return new self($abstract, $concrete, true);
    }

    public static function scoped(string $abstract, mixed $concrete): self
    {
        return new self($abstract, $concrete, false, true);
    }

    public function lifetime(): string
    {
        if ($this->shared) {
            return 'singleton';
        }

        if ($this->scoped) {
            return 'scoped';
        }

        return 'transient';
    }

    public function storesResolvedInstance(): bool
    {
        return $this->shared || $this->scoped;
    }
}
