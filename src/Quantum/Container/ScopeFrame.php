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
