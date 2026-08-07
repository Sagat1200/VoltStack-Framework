<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Context;

final readonly class SecurityAttributes
{
    public function __construct(
        public array $attributes = [],
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
