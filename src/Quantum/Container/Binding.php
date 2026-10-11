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
        public ?ScopeKind $scopeKind = null,
        public ?Visibility $visibility = null,
        public ?string $origin = null,
    ) {
        if ($shared && $scoped) {
            throw new InvalidArgumentException('A binding cannot be shared and scoped at the same time.');
        }
    }

    public static function transient(
        string $abstract,
        mixed $concrete,
        ?Visibility $visibility = null,
        ?string $origin = null,
    ): self {
        return new self($abstract, $concrete, visibility: $visibility, origin: $origin);
    }

    public static function singleton(
        string $abstract,
        mixed $concrete,
        ?Visibility $visibility = null,
        ?string $origin = null,
    ): self {
        return new self($abstract, $concrete, true, visibility: $visibility, origin: $origin);
    }

    public static function scoped(
        string $abstract,
        mixed $concrete,
        ?ScopeKind $scopeKind = null,
        ?Visibility $visibility = null,
        ?string $origin = null,
    ): self {
        return new self($abstract, $concrete, false, true, $scopeKind, $visibility, $origin);
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

    public function scopeKindName(): ?string
    {
        return $this->scopeKind?->value;
    }

    /**
     * Devuelve la visibilidad efectiva del binding.
     * Cuando no se declara, se asume Public por compatibilidad con el contrato actual.
     */
    public function visibility(): Visibility
    {
        return $this->visibility ?? Visibility::Public;
    }

    public function isPublic(): bool
    {
        return $this->visibility()->isPublic();
    }

    public function isPrivate(): bool
    {
        return $this->visibility()->isPrivate();
    }

    public function hasOrigin(): bool
    {
        return $this->origin !== null && $this->origin !== '';
    }

    public function origin(): ?string
    {
        return $this->origin;
    }
}
