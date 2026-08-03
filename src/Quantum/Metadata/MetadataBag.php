<?php

declare(strict_types=1);

namespace Quantum\Metadata;

final readonly class MetadataBag
{
    public function __construct(private array $items) {}

    public function all(): array
    {
        return $this->items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }
}
