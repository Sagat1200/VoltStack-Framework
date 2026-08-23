<?php

declare(strict_types=1);

namespace Quantum\Database\Seeder;

final class SeedReferenceStore
{
    /** @var array<string,mixed> */
    private array $references = [];

    public function set(string $key, mixed $value): void
    {
        $this->references[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->references);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->references[$key] ?? $default;
    }

    public function require(string $key): mixed
    {
        if (!$this->has($key)) {
            throw new \RuntimeException(sprintf('Seed reference [%s] is not available.', $key));
        }

        return $this->references[$key];
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->references;
    }
}
