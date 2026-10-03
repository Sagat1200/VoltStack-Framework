<?php

declare(strict_types=1);

namespace Quantum\Container;

/**
 * @internal
 */
final class ScopeFrame
{
    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    public function __construct(
        private readonly string $id,
        private readonly string $name = 'scope',
        private readonly ScopeKind $kind = ScopeKind::Generic,
        private readonly ?string $parentId = null,
        private readonly int $depth = 0,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): ScopeKind
    {
        return $this->kind;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function has(string $abstract): bool
    {
        return array_key_exists($abstract, $this->instances);
    }

    public function get(string $abstract): mixed
    {
        return $this->instances[$abstract];
    }

    public function put(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function flush(): void
    {
        $this->instances = [];
    }
}
