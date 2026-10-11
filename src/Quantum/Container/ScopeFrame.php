<?php

declare(strict_types=1);

namespace Quantum\Container;

/**
 * @internal
 */
final class ScopeFrame
{
    /**
     * @var array<string, array{instance: mixed, owner: ?ScopeKind}>
     */
    private array $instances = [];

    /**
     * @var array<string, mixed>
     */
    private array $seeds = [];

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
        return $this->instances[$abstract]['instance'];
    }

    public function put(string $abstract, mixed $instance, ?ScopeKind $owner = null): void
    {
        $this->instances[$abstract] = [
            'instance' => $instance,
            'owner' => $owner,
        ];
    }

    public function seed(string $id, mixed $value): void
    {
        $this->seeds[$id] = $value;
    }

    public function hasSeed(string $id): bool
    {
        return array_key_exists($id, $this->seeds);
    }

    public function getSeed(string $id): mixed
    {
        return $this->seeds[$id] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function seeds(): array
    {
        return $this->seeds;
    }

    public function resolvedInstanceCount(): int
    {
        return count($this->instances);
    }

    public function flush(bool $preserveWorkerOwnedEntries = false): void
    {
        if ($preserveWorkerOwnedEntries) {
            $this->instances = array_filter(
                $this->instances,
                static fn(array $entry): bool => $entry['owner'] === ScopeKind::Worker,
            );

            return;
        }

        $this->instances = [];
    }
}
